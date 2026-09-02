# Mikrotik Service Suspension Sync Plugin

Automatically synchronize UCRM service status with Mikrotik address lists for service suspension and activation. This plugin processes webhooks from UCRM service events and runs a daily background sync to ensure all services are in the correct state.

## Features

- **Real-time Webhook Processing**: Responds immediately to service status changes (add, edit, suspend, end, etc.)
- **Daily Synchronization**: Automated 24-hour sync to ensure all services are in the correct address list
- **Flexible Device Identification**: Supports multiple search methods:
  - Option 82 Circuit ID
  - Option 82 Remote ID
  - Active MAC address
  - Active IP address
- **Custom Attribute Support**: Uses a configurable service custom attribute to identify devices
- **Mikrotik DHCP Lookup**: Uses Mikrotik REST to query DHCP leases to find service IP addresses
- **Address List Management**: Automatically manages three address lists:
  - `Service_Active`: Active/running services
  - `Service_Suspend`: Suspended services (non-payment)
  - `Service_End`: Ended/archived services
  **Unknown IP Address List**
  - `Service_Unknown`: All remaining and *unknown* DHCP lease entries are added to this list to catch stragglers.
- **Automatically Create PCQ Queues**: Automatically build speed queues for each speed package in UCRM

- **Multi-Mikrotik Support**: Multiple headend routers can be managed from one UCRM instance.  Since it is based on the DHCP leases, each address list entry is applied to only the mikrotik router it should be on.

## Requirements

- UCRM 2.1.0 or later
- Mikrotik RouterOS with API access enabled
- PHP 7.2.5 or later
- Service profiles must have a custom attribute for device identification (serial number, MAC, etc.)
- For Option 82 searching: DHCP server must use option 82 to store device identifiers
- For MAC/IP searching: Standard Mikrotik DHCP (MAC and IP are always available)

## Installation

### Step 1: Configure Service Custom Attribute

1. In UCRM, navigate to Other > Custom Attributes
2. Click "+ Custom Attribute" button.
   - Name: Some descriptive name such as Device MAC, ONT Serial, or Device IP
   - Attribute Type: Service
   - Type: Text
   - Visible in Client Zone: No

3. After clicking save, note the numeric Attribute ID at the end of the URL.  You will need it when configuring the plugin.
   
3. In the plugin configuration, set "Search Custom Attribute" to the numeric ID (e.g., `5`, `12`)
4. Save

### Step 2: Download

Upload the plugin ZIP to the UCRM Admin > Plugins page in UCRM.

### Step 3: Configure Plugin

In UCRM Admin > Plugins > Installed Plugins > Mikrotik Service Suspension Sync:

1. **Mikrotik Connection Details**
   - IP Addresses (CSV): Comma-separated list of Mikrotik IPs (e.g., `192.168.1.1, 192.168.1.2, 192.168.1.3`)
   - Username: API user with firewall access (same user for all instances)
   - Password: API user password (same password for all instances)
   - API Port: Default is 443 for HTTPS. For unencrypted connections use 8728, for SSL use 8729 - applies to all instances

2. **DHCP Server Settings**
   - DHCP Server Name: Name of your DHCP server in Mikrotik. Leave blank to search all DHCP servers and include all matching leases in the address list regardless of which server they're on.

3. **Address List Names**
   - Service Active List: Name for active service addresses (default: "Service_Active")
   - Service Suspend List: Name for suspended service addresses (default: "Service_Suspend")
   - Service End List: Name for ended service addresses (default: "Service_End")

4. **Custom Attribute Key**
   - Enter the numeric value of the recently created Custom Attribute

5. **Choose the field to search in the Mikrotik DHCP Leases**
   Each setup is different.  You will need to see what unique identifier you want to use from your DHCP lease list.
   - Active MAC - The MAC address of the lease
   - Active IP - The IP of the lease (use this with caution because it could change)
   - Option 82 - Active Agent Circuit ID - The circuit ID that is passed along when using Option 82
   - Option 82 - Active Agent Remote ID - The remote ID that is passed along when using Option 82

6. **Optional Settings**
   - Enable HTTPS/SSL (TLS): Check to use encrypted connections to Mikrotik (enabled by default, use port 443 or 8729)
   - Ignore Certificate Errors: Check to allow self-signed certificates (only with SSL enabled)
   - Enable Debug Logging: Check to enable detailed debug output



### Step 4: Setup Mikrotik

1. Enable API on your Mikrotik device
2. Create an API user with the following permissions:
   - Read/Write on `/ip/firewall/address-list`
   - Read on `/ip/dhcp-server/lease`
3. Configure DHCP server appropriately:
   - **For Option 82 searching**: Ensure DHCP servers use option 82 to store circuit IDs or remote IDs
   - **For MAC searching**: MAC addresses are automatically stored by Mikrotik
   - **For IP searching**: IP addresses are automatically stored by Mikrotik

### Step 5: Schedule Daily Sync

In UCRM, create a scheduled task:

1. Admin > Plugins > Mikrotik Service Sync -> Configuration
2. Set Execution period to 24 hours.


### Step 6: Schedule Daily Sync

In UCRM, create a scheduled task:

1. Admin > Plugins > Mikrotik Service Sync
2. Copy the Public URL
3. Admin > Webhook
4. Click "+ Endpoint"
5. Paste URL into "URL" field.
6. Add `service.add`, `service.edit`, `service.postpone`, `service.suspend`, `service.suspend_cancel`, `service.end`, `service.archive`, and `service.delete`
7. Click "Save"

### Mikrotik API User Setup

Create the same API user on all Mikrotik instances and give them full permissions

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

The plugin supports multiple Mikrotik instances for servicing multiple locations:

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
   - **443**: Standard Mikrotik SSL/TLS port
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
Mikrotik REST Port: 80
Ignore Certificate Errors: N/A
```

#### SSL Connection with Valid Certificate (Default)
```
Enable SSL (TLS): Checked
Mikrotik REST Port: 443
Ignore Certificate Errors: Unchecked
```

#### SSL Connection with Self-Signed Certificate
```
Enable SSL (TLS): Checked
Mikrotik API Port: 443
Ignore Certificate Errors: Checked
```

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

Jim Bouse
