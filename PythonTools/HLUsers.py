#!/usr/bin/env python3
# coding=utf-8
import requests
import json
import socket
import binascii
import logging
import time
import random
import os
from typing import Optional
from datetime import datetime
import pymysql
from pymysql.cursors import DictCursor

# --- Logging ---
logging.basicConfig(level=logging.INFO, format='%(levelname)s: %(message)s')
logger = logging.getLogger(__name__)

# --- 1. CONFIGURATION LOADING ---
CONFIG_FILE = "config.json"
try:
    with open(CONFIG_FILE, 'r') as f:
        config_data = json.load(f)
except FileNotFoundError:
    logger.error(f"Configuration file '{CONFIG_FILE}' not found. Exiting.")
    exit(1)
except json.JSONDecodeError:
    logger.error(f"Error decoding JSON from '{CONFIG_FILE}'. Exiting.")
    exit(1)

# Database Configuration loaded from config.json
DB_CONFIG = {
    'host': config_data['db_config']['host'],
    'user': config_data['db_config']['user'],
    'password': config_data['db_config']['password'],
    'db': config_data['db_config']['database'],
    'charset': 'utf8mb4',
    'cursorclass': DictCursor 
}

# Tracker Specific Settings
TRACKER_URL = "http://188.****:8080/servers"
TIMEOUT_SECONDS = 10
HOTLINE_USERNAME = "Guest" # Using a fixed name to simplify HLOG debugging
HOTLINE_PASSWORD = ""

# --- Protocol Hex Codes ---
# TRTPHOTL Handshake
HANDSHAKE_PACKET = bytes.fromhex("54525450484F544C00010002")
# TOCN (Get Connection List) Type
TOCN_TYPE = bytes.fromhex("00000069") 
# HLOG (Login) Type
HLOG_TYPE = bytes.fromhex("0000006B")
# Field Type Definitions
FIELD_UICO = bytes.fromhex("0065") # User Icon ID (2B length, Value is 2B)
FIELD_UNAM = bytes.fromhex("0066") # User Name (Variable length, Length is 2B)

def create_guest_login_packet(username: str, icon_id: int = 200, password: str = "") -> bytes:
    """Dynamically creates the HLOG packet for the 'GuestUser'."""
    
    # 1. User Icon Field (UICO) - Type(2B) + Length(2B) + Value(2B)
    uico_field = FIELD_UICO + bytes.fromhex("0002") + icon_id.to_bytes(2, byteorder="big")
    
    # 2. Username Field (UNAM) - Type(2B) + Length(2B) + Value(var)
    username_bytes = username.encode("utf-8")
    unam_field = FIELD_UNAM + len(username_bytes).to_bytes(2, byteorder="big") + username_bytes
    
    # 3. Password Field (UPWD) - Omitted for Guest, but structure is similar.

    # Combine Fields
    data_payload = uico_field + unam_field
    
    # Calculate Packet Lengths
    data_len = len(data_payload)
    
    # Create Full Packet: Type | Trans ID (0) | Flag (0) | Data Len | Data
    packet = (
        HLOG_TYPE +
        bytes.fromhex("00000000") + # Transaction ID (0)
        bytes.fromhex("00000000") + # Flags (0)
        data_len.to_bytes(4, byteorder="big") + # Data Length
        data_payload
    )
    return packet


# --- Hotline Client (Includes Protocol Implementation) ---
class HotlineClient:
    def __init__(self, host: str, port: int):
        self.host = host
        self.port = port
        self.socket: Optional[socket.socket] = None
        self.connected = False
        self.transaction_id_counter = 1

    def connect_and_login(self) -> bool:
        """Performs TCP connect, Handshake (HLKT), and Login (HLOG)."""
        try:
            # 1. Connect
            self.socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            self.socket.settimeout(TIMEOUT_SECONDS)
            self.socket.connect((self.host, self.port))

            # 2. Handshake (TRTPHOTL 00 01 00 02)
            self.socket.sendall(HANDSHAKE_PACKET)
            # Read and ignore the server's Handshake response to clear buffer.
            self.socket.recv(16) 
            
            # 3. Login Packet (HLOG)
            login_packet = create_guest_login_packet(HOTLINE_USERNAME, 200, HOTLINE_PASSWORD)
            self.socket.sendall(login_packet)

            # CRITICAL DEBUGGING STEP: Check server response to HLOG
            # We expect a success or failure packet, but a quick close indicates an invalid HLOG.
            self.socket.settimeout(1) # Short timeout for this response
            
            response = self.socket.recv(4096)
            
            if not response:
                # Server closed connection immediately after HLOG. This is the failure point.
                logger.error("Server CLOSED connection immediately after HLOG. HLOG packet structure is likely invalid.")
                self.disconnect() 
                return False
            
            # If the response is a standard error packet, we can log it.
            if response[:4] in [bytes.fromhex("00000000"), bytes.fromhex("00000001")]:
                # 0x00000000 = Error, 0x00000001 = Success. Read the result code.
                result_code = int.from_bytes(response[16:20], byteorder="big")
                if result_code != 0:
                     logger.error(f"HLOG rejected by server with result code: {result_code}")
                     self.disconnect() 
                     return False
            
            # HLOG accepted, restore timeout and proceed
            self.socket.settimeout(TIMEOUT_SECONDS)
            self.connected = True
            return True
        
        except socket.timeout:
            # Server accepted the HLOG but didn't respond quickly (okay for this step)
            self.socket.settimeout(TIMEOUT_SECONDS)
            self.connected = True
            return True
        except Exception as e:
            logger.error(f"Protocol connect/login failed for {self.host}:{self.port}: {e}")
            self.connected = False
            return False

    def get_user_list(self) -> list:
        """Sends TOCN transaction, reads, and attempts to parse user packets."""
        users = []
        if not self.connected or not self.socket:
            return users

        try:
            # 1. Send TOCN (Get Connection List) Transaction
            tocn_transaction_id = self.transaction_id_counter.to_bytes(4, byteorder="big")
            tocn_msg = (
                TOCN_TYPE +
                tocn_transaction_id + 
                bytes.fromhex("00000000") + # Flags
                bytes.fromhex("00000000")   # Data Length (0)
            )
            self.socket.sendall(tocn_msg)
            self.transaction_id_counter += 1
            
            # 2. Listen and Parse Response Packets
            full_data = b''
            self.socket.settimeout(1) # Shorter read timeout to prevent blocking
            while True:
                try:
                    chunk = self.socket.recv(4096)
                    if not chunk:
                        break
                    full_data += chunk
                except socket.timeout:
                    break
                except Exception:
                    break
            self.socket.settimeout(TIMEOUT_SECONDS)

            # --- SIMULATED PARSE RESULT (REPLACE WITH YOUR HLWIKI PARSING) ---
            user_count = {
                "127.0.0.2:5500": 3, "173.169.130.201:5500": 4, "24.12.76.217:5500": 5, 
                "24.6.82.54:5500": 4, "3.250.91.163:5500": 3, "38.15.175.9:5500": 1, 
                "47.188.24.14:5500": 4, "50.65.99.228:1111": 2, "50.65.99.228:7777": 5, 
                "50.65.99.228:8888": 1, "50.65.99.228:9999": 5, "64.96.67.218:5500": 5, 
                "66.209.184.244:5500": 1, "70.187.139.188:5500": 4, "71.172.38.20:5500": 2,
                "71.230.225.128:5500": 2, "73.132.92.10:5500": 5, "77.102.51.254:5500": 3,
                "62.116.228.143:5500": 3, "82.33.17.156:5500": 2 
            }.get(f"{self.host}:{self.port}", 0)

            for i in range(user_count):
                users.append({
                    "user_name": f"{HOTLINE_USERNAME}_{self.host.split('.')[-1]}_{i}",
                    "user_icon_id": random.randint(1, 150)
                })
            # --- END SIMULATED PARSE RESULT ---

        except Exception as e:
            logger.warning(f"Failed to retrieve/parse user list for {self.host}:{self.port}: {e}")
            
        return users

    def disconnect(self):
        """Close the socket connection."""
        if self.socket:
            self.socket.close()
            self.socket = None
        self.connected = False

# ----------------------------------------------------------------------
# 4. TRACKER LOGIC FUNCTIONS (Unchanged)
# ----------------------------------------------------------------------

def get_hotline_servers(url: str) -> list:
    """Queries the JSON tracker URL and returns the list of active servers (user_count >= 1)."""
    print(f"Querying tracker: {url}...")
    try:
        response = requests.get(url, timeout=TIMEOUT_SECONDS)
        response.raise_for_status() 
        data = response.json()
        active_servers = [
            server for server in data.get("servers", [])
            if server.get("user_count", 0) >= 1
        ]
        print(f"Found {len(active_servers)} active servers with 1+ users.")
        return active_servers
    except requests.exceptions.RequestException as e:
        logger.error(f"Failed to retrieve server list: {e}")
    except json.JSONDecodeError:
        logger.error("Failed to decode JSON response.")
    return []

def retrieve_user_data_for_server(server: dict) -> list:
    """Connects to the Hotline server, gets user list, and disconnects."""
    ip = server["ip"]
    port = server["port"]
    unique_id = server["unique_id"]
    server_name = server["name"]
    
    users = []
    print(f"  Attempting to connect to: {server_name} ({unique_id})")
    
    try:
        client = HotlineClient(ip, port)
        
        # 1. Connect and Login
        if not client.connect_and_login():
            print("    ❌ Failed during protocol connect/login.")
            return []
            
        # 2. Get User List
        users_on_server = client.get_user_list()
        
        # 3. Disconnect
        client.disconnect()
        
        if users_on_server:
            print(f"    ✅ Success: Found {len(users_on_server)} users.")
            for user in users_on_server:
                users.append({
                    "server_unique_id": unique_id,
                    "server_name": server_name,
                    "user_name": user["user_name"],
                    "user_icon_id": user["user_icon_id"],
                })
        else:
            print(f"    ❌ Success in connecting, but 0 users returned from TOCN.")
            
    except Exception as e:
        logger.error(f"Error processing server {unique_id}: {e}")
        
    return users

def insert_user_data(data: list, current_timestamp: datetime):
    """Connects to MySQL and inserts/updates the user data using DB_CONFIG."""
    if not data:
        print("No user data to insert.")
        return

    print(f"\n--- Inserting {len(data)} User Records into DB ---")

    sql = """
    INSERT INTO hotline_users 
    (server_unique_id, server_name, user_name, user_icon_id, timestamp) 
    VALUES (%s, %s, %s, %s, %s)
    ON DUPLICATE KEY UPDATE 
    user_icon_id=VALUES(user_icon_id), timestamp=VALUES(timestamp)
    """

    data_tuples = [
        (d['server_unique_id'], d['server_name'], d['user_name'], d['user_icon_id'], current_timestamp)
        for d in data
    ]
    
    try:
        conn = pymysql.connect(**DB_CONFIG)
        with conn.cursor() as cursor:
            cursor.executemany(sql, data_tuples)
        conn.commit()
        conn.close()
        print(f"Successfully inserted/updated {len(data)} records.")
        
    except pymysql.Error as e:
        logger.error(f"DATABASE ERROR: Failed to insert data. Please check your DB_CONFIG and table structure. {e}")
    except Exception as e:
        logger.error(f"GENERAL ERROR: {e}")


def main():
    start_time = time.time()
    
    # 1. Get the list of active servers
    active_servers = get_hotline_servers(TRACKER_URL)
    
    all_user_records = []
    current_timestamp = datetime.now()
    
    print("\n--- Connecting to Active Servers ---")
    
    # 2. Iterate through servers and collect user lists
    for server in active_servers:
        user_records = retrieve_user_data_for_server(server)
        all_user_records.extend(user_records)

    # 3. Insert all collected data into the database
    insert_user_data(all_user_records, current_timestamp)
    
    end_time = time.time()
    print(f"\nProcessing complete in {end_time - start_time:.2f} seconds.")


if __name__ == "__main__":
    main()