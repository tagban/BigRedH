import requests
import mysql.connector
import schedule
import time
import sys
import os
import json
from datetime import datetime, timezone

# --- CONFIGURATION ---
# Get the absolute path to the directory where this script is located
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
# Look for config.json in the same directory
CONFIG_FILE = os.path.join(SCRIPT_DIR, 'config.json')

# URL for the server list JSON
SERVER_LIST_URL = "http://188.****:8080/servers"

# How many file records to insert in a single SQL query
BATCH_SIZE = 5000 
# --- END CONFIGURATION ---


def load_config(config_path):
    """
    Loads database config and skip list from an external JSON file.
    """
    try:
        with open(config_path, 'r') as f:
            config_data = json.load(f)
            
        db_config = config_data.get('db_config')
        servers_to_skip = config_data.get('servers_to_skip_refresh') 

        if db_config is None or servers_to_skip is None:
            print(f"Error: '{config_path}' is missing 'db_config' or 'servers_to_skip_refresh'.", file=sys.stderr)
            return None, None
            
        return db_config, servers_to_skip
        
    except FileNotFoundError:
        print(f"Error: Configuration file not found at {config_path}", file=sys.stderr)
        return None, None
    except json.JSONDecodeError:
        print(f"Error: Could not decode JSON from {config_path}. Check for syntax errors.", file=sys.stderr)
        return None, None
    except Exception as e:
        print(f"Error loading config: {e}", file=sys.stderr)
        return None, None


def get_server_list_from_db(db_config):
    """
    Fetches the list of server IDs directly from the hotline_servers table.
    """
    servers = []
    connection = None
    try:
        connection = mysql.connector.connect(**db_config)
        cursor = connection.cursor(dictionary=True)
        # Select all servers that aren't 'FAKE' or '127.0.0.x'
        cursor.execute(
            "SELECT unique_id, name FROM hotline_servers "
            "WHERE server_type != 'FAKE' AND ip NOT LIKE '127.0.0.%'"
        )
        servers = cursor.fetchall()
    except mysql.connector.Error as err:
        print(f"MySQL Error fetching server list: {err}", file=sys.stderr)
    finally:
        if connection and connection.is_connected():
            cursor.close()
            connection.close()
    return servers


def fetch_files_for_server(server_id):
    """
    Fetches the file list JSON for a single server.
    """
    file_url = f"http://188.****:8080/servers/{server_id}/files"
    try:
        response = requests.get(file_url, timeout=30)
        response.raise_for_status()
        return response.json()
    except requests.exceptions.Timeout:
        print(f"  -> Request timed out for {file_url}", file=sys.stderr)
    except requests.exceptions.HTTPError as e:
        print(f"  -> HTTP error {e.response.status_code} from {file_url}", file=sys.stderr)
    except requests.exceptions.RequestException as e:
        print(f"  -> Failed to fetch data from {file_url}. {e}", file=sys.stderr)
    except requests.exceptions.JSONDecodeError:
        print(f"  -> Failed to decode JSON from {file_url}", file=sys.stderr)
    return None


def log_script_run(db_config, script_name):
    """
    Logs the successful completion time of a script to the script_log table.
    """
    connection = None
    try:
        connection = mysql.connector.connect(**db_config)
        cursor = connection.cursor()
        
        query = """
        INSERT INTO script_log (script_name, last_run_utc) 
        VALUES (%s, %s)
        ON DUPLICATE KEY UPDATE last_run_utc = %s
        """
        # Log the current time in UTC
        utc_now = datetime.now(timezone.utc)
        cursor.execute(query, (script_name, utc_now, utc_now))
        connection.commit()
        
    except mysql.connector.Error as err:
        print(f"MySQL Error logging script run: {err}", file=sys.stderr)
    finally:
        if connection and connection.is_connected():
            cursor.close()
            connection.close()


def main_file_indexing_job():
    """
    Main task to be run by the scheduler.
    Fetches all servers from DB, then fetches file lists for each one.
    """
    start_time = datetime.now()
    print(f"[{start_time.strftime('%Y-%m-%d %H:%M:%S')}] Starting file indexing job...")

    db_config, servers_to_skip_refresh = load_config(CONFIG_FILE)
    if db_config is None or servers_to_skip_refresh is None:
        print("Halting job: Could not load configuration.")
        return

    server_list = get_server_list_from_db(db_config)
    if not server_list:
        print("Halting job: No servers found in the database.")
        return

    print(f"Found {len(server_list)} servers to process.")
    
    connection = None
    total_files_processed = 0
    
    try:
        connection = mysql.connector.connect(**db_config)
        cursor = connection.cursor()

        insert_query = """
        INSERT INTO hotline_files (
            server_id, name, full_path, parent_path, size, 
            is_folder, type_code, creator_code
        ) VALUES (
            %s, %s, %s, %s, %s, %s, %s, %s
        )
        """
        
        batch_files_to_insert = []

        for server in server_list:
            server_id = server['unique_id']
            server_name = server['name']
            
            print(f"Processing server: {server_name} ({server_id})")

            # 1. Check if server is in the skip list
            if server_id in servers_to_skip_refresh:
                print("  -> In skip list. Retaining old data.")
                continue

            # 2. Clear all *old* data for this specific server
            try:
                cursor.execute("DELETE FROM hotline_files WHERE server_id = %s", (server_id,))
                # We commit deletion here, so if the script fails mid-run,
                # we don't end up with partial old data.
                connection.commit() 
            except mysql.connector.Error as del_err:
                print(f"  -> MySQL Error deleting old files: {del_err}", file=sys.stderr)
                connection.rollback()
                continue # Skip this server if we can't delete its old files

            # 3. Fetch new file list
            file_data_json = fetch_files_for_server(server_id)
            if file_data_json is None or file_data_json.get('status') != 'ok':
                print(f"  -> Skipping this server (no valid data received).")
                continue

            paths_dict = file_data_json.get('paths')
            if not paths_dict:
                print(f"  -> Server has no file paths listed.")
                continue

            file_count_for_this_server = 0
            
            # 4. Process and prepare file data
            for path, files in paths_dict.items():
                for file_item in files:
                    file_count_for_this_server += 1
                    
                    # *** FIX: Standardize path logic ***
                    if path == '/':
                        parent_path = '/'
                        full_path = '/' + file_item.get('name', 'Unknown')
                    else:
                        # Ensure path starts and ends with a slash
                        clean_path = '/' + path.strip('/') + '/'
                        parent_path = clean_path
                        full_path = clean_path + file_item.get('name', 'Unknown')
                    
                    # Add folder suffix if it's a directory
                    if file_item.get('is_folder') and not full_path.endswith('/'):
                        full_path += '/'
                    
                    data_tuple = (
                        server_id,
                        file_item.get('name', 'Unknown'),
                        full_path,
                        parent_path,
                        file_item.get('size'),
                        file_item.get('is_folder', False),
                        file_item.get('type_code'),
                        file_item.get('creator_code')
                    )
                    batch_files_to_insert.append(data_tuple)

                    # 5. Insert in batches if BATCH_SIZE is reached
                    # *** THIS BLOCK IS NOW INDENTED CORRECTLY ***
                    if len(batch_files_to_insert) >= BATCH_SIZE:
                        try:
                            print(f"  -> Writing batch of {len(batch_files_to_insert)} records to DB...")
                            cursor.executemany(insert_query, batch_files_to_insert)
                            connection.commit()
                            batch_files_to_insert = [] # Clear the batch
                        except mysql.connector.Error as err:
                            print(f"  -> MySQL Error inserting batch: {err}", file=sys.stderr)
                            connection.rollback()
                            # Clear batch to avoid re-inserting bad data
                            batch_files_to_insert = []

            print(f"  -> Found {file_count_for_this_server} files/folders.")
            total_files_processed += file_count_for_this_server
            
            # Note: The batch check is no longer here


        # 6. Insert any remaining files after the loop finishes
        if batch_files_to_insert:
            try:
                print(f"Writing final batch of {len(batch_files_to_insert)} records to DB...")
                cursor.executemany(insert_query, batch_files_to_insert)
                connection.commit()
            except mysql.connector.Error as err:
                print(f"MySQL Error inserting final batch: {err}", file=sys.stderr)
                connection.rollback()

    except mysql.connector.Error as err:
        print(f"Main MySQL Error: {err}", file=sys.stderr)
    finally:
        if connection and connection.is_connected():
            cursor.close()
            connection.close()

    end_time = datetime.now()
    print(f"[{end_time.strftime('%Y-%m-%d %H:%M:%S')}] Indexing job finished.")
    print(f"Processed {total_files_processed} files/folders in {end_time - start_time}.")
    
    # Log successful run to database
    log_script_run(db_config, 'file_indexer')


# --- Main Execution ---
if __name__ == "__main__":
    
    # Run the job once immediately on startup
    main_file_indexing_job()
    
    # Schedule the job to run every 5 days at 3:00 AM server time
    schedule.every(5).days.at("03:00").do(main_file_indexing_job)
    
    print("Scheduler started. Script will run every 5 days at 03:00.")
    print("Press Ctrl+C to stop the script.")

    try:
        while True:
            schedule.run_pending()
            time.sleep(60) # Check for pending jobs every minute
    except KeyboardInterrupt:
        print("\nScheduler stopped.")




