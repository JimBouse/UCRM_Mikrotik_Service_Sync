# Mikrotik Service Suspension Sync Plugin

Automatically synchronize UCRM service status with Mikrotik address lists for service suspension and activation. This plugin processes webhooks from UCRM service events and runs a daily background sync to ensure all services are in the correct state.

## Features

- **Real-time Webhook Processing**: Responds immediately to service status changes (add, edit, suspend, end, etc.)
- **Daily Synchronization**: Automated 24-hour sync to ensure all services are in the correct address list
- **Flexible Device Identification**: Supports multiple search methods:
  - Option 82 Circuit ID (default, for ONT serial numbers)
  - Option 82 Remote ID
  - Active MAC address
  - Active IP address
- **Custom Attribute Support**: Uses a configurable service custom attribute to identify devices
- **Mikrotik DHCP Lookup**: Queries Mikrotik DHCP leases to find service IP addresses using flexible search columns
- **Address List Management**: Automatically manages three address lists:
  - `Service_Active`: Active/running services
  - `Service_Suspend`: Suspended services (non-payment)
  - `Service_End`: Ended/archived services
- **Detailed Logging**: Comprehensive logging for debugging and audit trails
- **Duplicate Prevention**: Prevents duplicate webhook processing within 24 hours
- **Multi-Mikrotik Support**: Redundancy across multiple Mikrotik devices with failover

## Requirements

- UCRM 2.1.0 or later
- Mikrotik RouterOS with API access enabled
- PHP 7.2.5 or later
- Service profiles must have a custom attribute for device identification (serial number, MAC, etc.)
- For Option 82 searching: DHCP server must use option 82 to store device identifiers
- For MAC/IP searching: Standard Mikrotik DHCP (MAC and IP are always available)

## Installation

### Step 1: Download and Extract

Download the plugin ZIP file and extract it into your UCRM plugins directory:

```bash
unzip MikrotikServiceSync.zip -d /path/to/ucrm/plugins/
```

### Step 2: Install Dependencies

Navigate to the plugin directory and install Composer dependencies:

```bash
cd /path/to/ucrm/plugins/mikrotik-service-sync
composer install
```

### Step 3: Configure Plugin

In UCRM Admin > Plugins > Installed Plugins > Mikrotik Service Suspension Sync:

1. **Mikrotik Connection Details**
   - IP Addresses (CSV): Comma-separated list of Mikrotik IPs (e.g., `192.168.1.1, 192.168.1.2, 192.168.1.3`)
   - Username: API user with firewall access (same user for all instances)
   - Password: API user password (same password for all instances)
   - API Port: Default is 443 for HTTPS. For unencrypted connections use 8728, for SSL use 8729 - applies to all instances

2. **DHCP Server Settings**
   - DHCP Server Name: Name of your DHCP server in Mikrotik (e.g., "default"). Leave blank to search all DHCP servers and include all matching leases in the address list regardless of which server they're on.

3. **Address List Names**
   - Service Active List: Name for active service addresses (default: "Service_Active")
   - Service Suspend List: Name for suspended service addresses (default: "Service_Suspend")
   - Service End List: Name for ended service addresses (default: "Service_End")

4. **Search Configuration**
   - Search Custom Attribute: The numeric custom attribute ID on the service profile containing the device identifier (serial number, MAC address, etc.) to search for in Mikrotik DHCP leases. This is the attribute ID/key number, not the attribute name (e.g., enter `5` or `12` if your attribute ID is 5 or 12)
   - DHCP Lease Column to Search: Which Mikrotik DHCP lease field to search:
     - `Active MAC`: Search by device MAC address
     - `Active IP`: Search by device IP address
     - `Option 82 - Circuit ID`: Search by DHCP option 82 circuit ID (default)
     - `Option 82 - Remote ID`: Search by DHCP option 82 remote ID

5. **Optional Settings**
   - Enable HTTPS/SSL (TLS): Check to use encrypted connections to Mikrotik (enabled by default, use port 443 or 8729)
   - Ignore Certificate Errors: Check to allow self-signed certificates (only with SSL enabled)
   - Enable Debug Logging: Check to enable detailed debug output

### Step 4: Configure Service Custom Attribute

1. In UCRM, navigate to Settings > Service
2. Create or configure a custom attribute for device identifier:
   - Note the numeric Attribute ID (shown in the URL or attribute list)
   - Type: Text
   - Enter the appropriate value for each service:
     - If searching by MAC: Enter the device MAC address
     - If searching by serial: Enter the device serial number
     - If searching by option 82: Enter the circuit ID or remote ID
     - If searching by IP: Enter the device IP address
3. In the plugin configuration, set "Search Custom Attribute" to the numeric ID (e.g., `5`, `12`)
4. Save

### Step 5: Setup Mikrotik

1. Enable API on your Mikrotik device
2. Create an API user with the following permissions:
   - Read/Write on `/ip/firewall/address-list`
   - Read on `/ip/dhcp-server/lease`
3. Configure DHCP server appropriately:
   - **For Option 82 searching**: Ensure DHCP servers use option 82 to store circuit IDs or remote IDs
   - **For MAC searching**: MAC addresses are automatically stored by Mikrotik
   - **For IP searching**: IP addresses are automatically stored by Mikrotik

### Step 6: Schedule Daily Sync

In UCRM, create a scheduled task:

1. Admin > Tools > Webhooks > Scheduled Commands
2. Add New:
   - Plugin: Mikrotik Service Suspension Sync
   - Schedule: Daily (configure time)
   - Command: `php main.php`

## Configuration Examples

### UCRM Plugin Configuration (Searching by Option 82 Circuit ID)

```
Mikrotik IP Addresses: 192.168.1.1, 192.168.1.2, 192.168.1.3
Mikrotik Username: ucrm-api
Mikrotik Password: YourSecurePassword
Mikrotik API Port: 443
Enable HTTPS: Checked (default)
Ignore Certificate Errors: Unchecked (default)
DHCP Server Name: default
Service Active List Name: Service_Active
Service Suspend List Name: Service_Suspend
Service End List Name: Service_End
Search Custom Attribute: 5
DHCP Lease Column to Search: Option 82 - Circuit ID
Enable Debug Logging: Unchecked
```

### UCRM Plugin Configuration (Searching by MAC Address)

```
Mikrotik IP Addresses: 192.168.1.1, 192.168.1.2, 192.168.1.3
Mikrotik Username: ucrm-api
Mikrotik Password: YourSecurePassword
Mikrotik API Port: 443
Enable HTTPS: Checked (default)
Ignore Certificate Errors: Unchecked (default)
DHCP Server Name: default
Service Active List Name: Service_Active
Service Suspend List Name: Service_Suspend
Service End List Name: Service_End
Search Custom Attribute: 8
DHCP Lease Column to Search: Active MAC
Enable Debug Logging: Unchecked
```

### UCRM Plugin Configuration (All DHCP Servers)

Leave "DHCP Server Name" blank to search all DHCP servers:

```
Mikrotik IP Addresses: 192.168.1.1, 192.168.1.2, 192.168.1.3
Mikrotik Username: ucrm-api
Mikrotik Password: YourSecurePassword
Mikrotik API Port: 443
Enable HTTPS: Checked (default)
Ignore Certificate Errors: Unchecked (default)
DHCP Server Name: (leave blank)
Service Active List Name: Service_Active
Service Suspend List Name: Service_Suspend
Service End List Name: Service_End
Search Custom Attribute: 5
DHCP Lease Column to Search: Option 82 - Circuit ID
Enable Debug Logging: Unchecked
```

### Mikrotik API User Setup

Create the same API user on all Mikrotik instances:

```
/user add name=ucrm-api password=YourSecurePassword disabled=no
/user/group/permission set name=full number=0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16
/user/aclgroup add name=ucrm-api permissions=full
/user/aclset name=ucrm-api default-policy=deny target-address-list=address-list policy=read,write
```

### DHCP Option 82 Configuration (If searching by option 82)

Ensure your Mikrotik DHCP server is configured to use option 82:

```
/ip/dhcp-server set default use-option-82=yes
```

### Address List Setup

The plugin automatically manages these address lists. Pre-create them in Mikrotik if needed:

```
/ip/firewall/address-list add list="Service_Active"
/ip/firewall/address-list add list="Service_Suspend"
/ip/firewall/address-list add list="Service_End"
```

## Search Methods

The plugin matches UCRM services to Mikrotik devices by searching for a value from a custom service attribute in Mikrotik DHCP leases. You specify which attribute to use via the "Search Custom Attribute" setting (the numeric attribute ID).

### Option 82 - Circuit ID (Default)
- **Use when**: Mikrotik DHCP server stores device circuit IDs in option 82
- **Requires**: DHCP option 82 to be enabled on Mikrotik
- **Search value**: Device serial number or circuit ID from the service custom attribute
- **Example**: `ABK23H4012`

### Option 82 - Remote ID
- **Use when**: Mikrotik DHCP server stores device remote IDs in option 82
- **Requires**: DHCP option 82 to be enabled on Mikrotik
- **Search value**: Device remote ID from the service custom attribute
- **Example**: `REMOTE-12345`

### Active MAC
- **Use when**: You want to identify devices by their MAC address
- **Requires**: Nothing special, MAC addresses are always available in DHCP leases
- **Search value**: Device MAC address from the service custom attribute
- **Example**: `AA:BB:CC:DD:EE:FF`

### Active IP
- **Use when**: You want to look up by device IP address directly
- **Requires**: Nothing special, IP addresses are always available in DHCP leases
- **Search value**: Device IP address from the service custom attribute
- **Example**: `192.168.1.100`

## DHCP Server Filtering

The plugin supports flexible DHCP server filtering:

### Specific DHCP Server
- **Configuration**: Set "DHCP Server Name" to a specific server name (e.g., "default")
- **Behavior**: Only DHCP leases from that specific server are queried and added to address lists
- **Use case**: Multi-server setups where you want to isolate services by server

### All DHCP Servers
- **Configuration**: Leave "DHCP Server Name" blank
- **Behavior**: The plugin queries all DHCP servers and includes all matching leases in the address list
- **Use case**: Single server or when you want all matching devices included regardless of server

## Webhook Events Supported

The plugin listens for and processes these UCRM service events:

| Event | Action | Target List |
|-------|--------|-------------|
| `service.add` | New service created | Service_Active |
| `service.edit` | Service edited | Depends on new status |
| `service.postpone` | Service postponed | Service_Active |
| `service.suspend` | Service suspended (non-payment) | Service_Suspend |
| `service.suspend_cancel` | Suspension cancelled | Service_Active |
| `service.end` | Service ended | Service_End |
| `service.archive` | Service archived | Service_End |
| `service.delete` | Service deleted | Service_End |

## Address List Comments

Each address list entry includes a detailed comment for audit purposes:

```
Client ID: 12, Service ID: 456, Search Value: ABC123XYZ
```

For active services with speed information, Speed_Package lists include:

```
Speed Package 50Mbps down / 10Mbps up - Client ID: 12, Service ID: 456
```

This allows easy identification of which client and service an address belongs to.

## Multi-Mikrotik Support

The plugin supports multiple Mikrotik instances for redundancy and load distribution:

### DHCP Query Failover
When looking up a device IP:
1. Queries the first Mikrotik in the list
2. If not found, tries the next one
3. Returns the first IP found
4. Logs all attempts

### Address List Management
When adding/removing addresses from firewall lists:
- **Add**: Adds to all configured Mikrotik instances (ensures synchronization)
- **Remove**: Removes from all configured Mikrotik instances
- Success if added to **at least one** instance
- Partial success is logged as warnings

### Configuration
- All Mikrotik instances must use **the same username/password** and **port**
- Each instance can have a different IP address
- Recommended: 2-3 Mikrotik instances for redundancy
- Example CSV: `192.168.1.1, 192.168.1.2, 192.168.1.3`

### Logging
Multi-Mikrotik operations are logged with:
- Individual success/failure for each instance
- Success count and failure count
- Which instance returned the result (for DHCP lookups)

## SSL/TLS Configuration

The plugin supports secure connections to Mikrotik devices using SSL/TLS:

### Enabling SSL

1. In UCRM Admin > Plugins > Installed Plugins > Mikrotik Service Suspension Sync
2. Check "Enable SSL (TLS)" to use encrypted connections
3. Update your API port:
   - **8729**: Standard Mikrotik SSL/TLS port
   - **Custom**: If your Mikrotik uses a different SSL port

### Certificate Verification

By default, the plugin verifies SSL certificates. For environments with self-signed certificates:

1. Check "Ignore Certificate Errors" to disable verification
2. This allows connections to self-signed or invalid certificates
3. **Warning**: This reduces security, only use in trusted networks

### Configuration Examples

#### Plain TCP Connection (Unencrypted)
```
Enable SSL (TLS): Unchecked
Mikrotik API Port: 8728
Ignore Certificate Errors: N/A
```

#### SSL Connection with Valid Certificate (Default)
```
Enable SSL (TLS): Checked
Mikrotik API Port: 443
Ignore Certificate Errors: Unchecked
```

#### SSL Connection with Self-Signed Certificate
```
Enable SSL (TLS): Checked
Mikrotik API Port: 443
Ignore Certificate Errors: Checked
```

### Mikrotik SSL Setup

To enable SSL on your Mikrotik:

1. Generate or install a certificate
2. Configure the API to use SSL on port 8729:
   ```
   /ip/service set api-ssl enabled=yes
   ```
3. Verify SSL is listening:
   ```
   /ip/service print
   ```
4. Test connection: `openssl s_client -connect mikrotik_ip:8729`

## PCQ Script Management

The plugin automatically manages Mikrotik PCQ (Per-Connection Queue) scripts during daily synchronization:

### How It Works

1. **Script Fetch**: During daily sync, the plugin fetches the `update_pcq_script.txt` file from the plugin's public directory
2. **Script Import**: The fetched script is imported into each Mikrotik instance
3. **Script Execution**: The `update_pcq` script is executed on all configured Mikrotik hosts

### PCQ Script Files

The plugin looks for PCQ scripts in the plugin's public directory:
- `public/update_pcq_script.txt` - The main PCQ script to import and run
- `public/update_pcq.txt` - Output/cache file from the last fetch

### Configuration

PCQ script management happens automatically during daily sync. No additional configuration is required. The plugin:
- Runs automatically as part of the scheduled daily sync (the `php main.php` command)
- Requires the plugin's public URL to be accessible (configured in UCRM)
- Logs all fetch/import/execution operations for debugging

### Troubleshooting PCQ Scripts

**Symptoms**: PCQ script operations fail in logs

**Solutions**:
- Verify the plugin's public URL is accessible and contains the `update_pcq_script.txt` file
- Check that the script file is readable by the web server
- Review plugin logs for specific error messages from Mikrotik
- Manually test script execution: `/system/script/run update_pcq`
- Verify the API user has execute permissions on `/system/script`



Plugin logs are available in UCRM Admin > Tools > Logs > Plugin Logs

The log file includes:
- Webhook processing events
- DHCP lease lookups
- Address list modifications
- Daily sync results
- Errors and warnings

Enable Debug Logging in plugin settings for more detailed output.

## Troubleshooting

### No IP Found for Service

**Symptoms**: Service webhook processes but logs show "No DHCP lease found"

**Solutions**:
- Verify the numeric custom attribute ID is correctly set in plugin configuration
- Verify the custom attribute value is populated on the service in UCRM
- Check Mikrotik DHCP server has the device lease with matching search value
- Verify search column setting matches where the value appears in Mikrotik DHCP leases
- If using Option 82, verify option 82 is enabled: `/ip/dhcp-server set default use-option-82=yes`
- Enable debug logging and check detailed logs for attribute matching information

### Connection Failed to Mikrotik

**Symptoms**: Logs show "Failed to connect to Mikrotik at X.X.X.X:8728"

**Solutions**:
- Verify Mikrotik API is enabled
- Check host/IP and port are correct
- Verify firewall rules allow API access
- Ensure API user has proper permissions
- Test with: `telnet mikrotik_ip 8728`

### Webhooks Not Being Triggered

**Symptoms**: Plugin log shows no webhook events

**Solutions**:
- Verify UCRM is configured to send webhooks to the plugin
- Check UCRM webhook settings for service events
- Verify plugin public endpoint is accessible
- Check UCRM system logs for webhook errors

### Address List Entry Not Created

**Symptoms**: Webhook processes successfully but address isn't in list

**Solutions**:
- Verify address list exists in Mikrotik
- Check Mikrotik user permissions on address-list resource
- Review plugin logs for specific error messages
- Manually test adding to address list via Mikrotik terminal

## Performance Considerations

- Daily sync may take time with large service counts (100+ services)
- Webhook processing is near-instant but depends on Mikrotik connectivity
- Consider scheduling daily sync during off-peak hours
- Large DHCP lease tables may slow DHCP lookups

## Data Storage

The plugin stores state in `data/plugin.json`:
- Last sync timestamp
- Processed webhook tracking (prevents duplicates)
- Current service states

This file is preserved when updating the plugin.

## Support & Troubleshooting

For issues, enable debug logging and check:
1. Plugin logs: UCRM Admin > Tools > Logs > Plugin Logs
2. UCRM system logs for webhook delivery issues
3. Mikrotik logs for API errors

## Version History

### v1.2.0 (SSL/TLS & Flexible Device Search)
- Added SSL/TLS support for secure Mikrotik connections (port 8729)
- Added certificate error tolerance for self-signed certificates
- Implemented flexible DHCP device search by multiple columns:
  - Option 82 Circuit ID (default)
  - Option 82 Remote ID
  - Active MAC address
  - Active IP address
- Made DHCP server filtering optional (blank searches all servers)
- Made address list names optional with sensible defaults
- Simplified webhook processing to use custom attributes directly
- Improved stream resource handling for SSL connections

### v1.1.0 (Multi-Mikrotik Support)
- Added support for multiple Mikrotik instances (CSV configuration)
- Implemented DHCP query failover across Mikrotik instances
- Address list operations now synchronize across all instances
- Improved logging for multi-instance operations
- Updated configuration to use CSV for IP addresses

### v1.0.0 (Initial Release)
- Basic webhook processing for all service events
- Daily synchronization
- DHCP option 82 lookup
- Address list management
- Custom attribute support

## License

MIT License

## Author

LagoMar Networks
