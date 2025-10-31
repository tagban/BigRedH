import requests
import mysql.connector
import schedule
import time
import sys
import os
import json
from datetime import datetime, timezone  # <-- Import timezone

# --- CONFIGURATION ---
# Get the absolute path to the directory where this script is located
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
# Look for config.json in the same directory
CONFIG_FILE = os.path.join(SCRIPT_DIR, 'config.json')

# URL for the server list JSON
SERVER_LIST_URL = "http://188.****:8080/servers"
# --- END CONFIGURATION ---


def load_config(config_path):
    """
    Loads database config from an external JSON file.
    """
    try:
        with open(config_path, 'r') as f:
            config_data = json.load(f)
            
        db_config = config_data.get('db_config')

        if db_config is None:
            print(f"Error: '{config_path}' is missing 'db_config'.", file=sys.stderr)
            return None
            
        return db_config
        
    except FileNotFoundError:
        print(f"Error: Configuration file not found at {config_path}", file=sys.stderr)
        return None
    except json.JSONDecodeError:
        print(f"Error: Could not decode JSON from {config_path}. Check for syntax errors.", file=sys.stderr)
        return None
    except Exception as e:
        print(f"Error loading config: {e}", file=sys.stderr)
        return None


def fetch_server_data(url):
    """
    Fetches the server list from the given URL.
    Returns the list of servers or None on failure.
    """
    try:
        response = requests.get(url, timeout=10)
        response.raise_for_status() 
        data = response.json()
        return data.get('servers')
    except requests.exceptions.RequestException as e:
        print(f"Error: Failed to fetch server data. {e}", file=sys.stderr)
    except requests.exceptions.JSONDecodeError:
        print(f"Error: Failed to decode JSON from {url}", file=sys.stderr)
        
    return None

def update_database(server_list, db_config):
    """
    Connects to the DB, truncates the table, and inserts the new list.
    """
    if not server_list:
        print("No server data provided. Skipping database update.")
        return 0
        
    connection = None
    try:
        connection = mysql.connector.connect(**db_config)
        cursor = connection.cursor()

        # 1. Clear the table
        cursor.execute("TRUNCATE TABLE hotline_servers")

        # 2. Prepare for insertion
        insert_query = """
        INSERT INTO hotline_servers (
            unique_id, name, description, ip, port, user_count, 
            server_type, filtered, filtered_by, last_checked_in, mirror_sources
        ) VALUES (
            %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s
        )
        """
        
        data_to_insert = []
        for server in server_list:
            mirror_sources_str = ",".join(server.get('mirror_sources', []))
            
            data_tuple = (
                server.get('unique_id'),
                server.get('name'),
                server.get('description'),
                server.get('ip'),
                server.get('port'),
                server.get('user_count'),
                server.get('server_type'),
                server.get('filtered'),
                server.get('filtered_by'),
                server.get('last_checked_in'),
                mirror_sources_str
            )
            data_to_insert.append(data_tuple)
        
        # 3. Insert all new data
        if data_to_insert:
            cursor.executemany(insert_query, data_to_insert)
            connection.commit()
            return len(data_to_insert)
        
    except mysql.connector.Error as err:
        print(f"MySQL Error: {err}", file=sys.stderr)
        if connection:
            connection.rollback()
    finally:
        if connection and connection.is_connected():
            cursor.close()
            connection.close()
            
    return 0

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
        utc_now = datetime.now(timezone.utc) # <-- Uses UTC
        cursor.execute(query, (script_name, utc_now, utc_now))
        connection.commit()
        
    except mysql.connector.Error as err:
        print(f"MySQL Error logging script run: {err}", file=sys.stderr)
    finally:
        if connection and connection.is_connected():
            cursor.close()
            connection.close()

def main_job():
    """
    The main task to be run by the scheduler.
    """
    print(f"[{datetime.now()}] Running hourly server update...")
    
    db_config = load_config(CONFIG_FILE)
    if db_config is None:
        print("Halting job: Could not load configuration.")
        return

    server_data = fetch_server_data(SERVER_LIST_URL)
    
    if server_data is not None:
        inserted_count = update_database(server_data, db_config)
        print(f"[{datetime.now()}] Update complete. {inserted_count} servers loaded into database.")
        # Log successful run
        log_script_run(db_config, 'server_updater')
    else:
        print(f"[{datetime.now()}] Update failed. No data fetched. Database was not modified.")

# --- Main Execution ---
if __name__ == "__main__":
    
    # Run the job once immediately on startup
    print("Running initial server update...")
    main_job()
    
    # Schedule the job to run every hour
    schedule.every(1).hour.do(main_job)
    
    print("Scheduler started. Script will run every hour.")
    print("Press Ctrl+C to stop the script.")

    try:
        while True:
            schedule.run_pending()
            time.sleep(60) # Check for pending jobs every minute
    except KeyboardInterrupt:
        print("\nScheduler stopped.")

