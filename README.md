# UCRM_Mikrotik_Service_Sync
The Mikrotik Service Sync plugin automatically synchronizes service status between UCRM and Mikrotik routers.

`service-sync.php` handles a UCRM service webhook and maintains these Mikrotik firewall
address lists:

| UCRM service status | Mikrotik address list |
| --- | --- |
| Active | `Service_Active` |
| Suspended | `Service_Suspend` |
| Ended, Cancelled | `Service_End` |

Before adding an address to its target list, the script removes that address from all
three managed lists. It uses a service IP address when present and otherwise finds the
DHCP lease whose comment or MAC address matches the configured UCRM service attribute.

## Configuration

Set these environment variables for the PHP process:

```text
UCRM_URL=https://uisp.example.net
UCRM_API_TOKEN=...
MIKROTIK_URL=https://router.example.net
MIKROTIK_USERNAME=...
MIKROTIK_PASSWORD=...
UCRM_LEASE_ATTRIBUTE=serial
```

`MIKROTIK_URL` must point to the router base URL with its REST service enabled. The
service attribute named by `UCRM_LEASE_ATTRIBUTE` is compared with DHCP lease comments
and MAC addresses.

Configure UCRM to POST service-change webhooks to `service-sync.php`. The webhook may
include a complete `service` object, or a `serviceId` (or `id`), which is then fetched
from UCRM.

Run the daily reconciliation with:

```sh
php service-sync.php daily
```

Schedule that command once a day with the same environment variables.
