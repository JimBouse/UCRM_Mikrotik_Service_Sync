# TODOs

Notes for work that is planned but not implemented yet.

## One IP per ONT (not started)

**Goal:** Give each subscriber only one IP at a time so a switch behind the ONT cannot get extra DHCP leases.

**Decided:** Shared customer VLAN with Option 82 (circuit/remote ID from the OLT/ONU). Newest MAC wins (router swap replaces the old lease).

**Do not use:** Bridge/switch MAC counts, firewall `connection-limit`, hotspot `shared-users`, or shrinking the DHCP pool. Those do not key off Option 82 on a shared VLAN. OLT/ONU `max-hosts=1` is the cleanest GPON control but is not MikroTik.

### 1. DHCP lease-script (real time)

- Put a `lease-script` on the **customer** DHCP server only (`dhcpServerName`). Do not attach it to LAN DHCP on the same router.
- On bind (`$leaseBound=1`):
  1. Read this lease’s Option 82 field (`active-agent-*` then `agent-*`, same field as the plugin search column).
  2. Find other leases on that server with the same Option 82 value and a different MAC.
  3. Remove those leases (static or dynamic). Newest device keeps the IP it just got; the old reservation returns to the pool.
- `lease-script` runs **after** the lease is created, so a second IP can exist for a moment. That is expected on RouterOS; there is no clean pre-NAK per Option 82.
- Two simultaneous requests are rare; the last bind script to finish wins.

### 2. Collapse duplicates in `update_dhcp_leases` (nightly backstop)

Nightly make-static currently turns every dynamic lease into a reservation, including a second device behind a switch. Before prune/make-static:

1. Collapse duplicates (same Option 82, keep newest `last-seen`, remove the rest)
2. Prune stale last-seen
3. Make remaining dynamic leases static

### 3. ARP lock-down (manual MikroTik setting)

Recommended on the customer DHCP path; do **not** have the plugin silently change interface ARP:

- DHCP server: `add-arp=yes`
- Customer interface: `arp=reply-only`

Only DHCP-installed ARP entries forward, so a PC behind the switch cannot pick a static IP and skip DHCP.

### Optional plugin work (when we implement)

- New helper (same fetch/import pattern as `public/update_dhcp_leases.txt`) with the collapse / newest-wins logic.
- Nightly: set `/ip dhcp-server lease-script` on the configured server so binds run that helper (`$leaseBound`, `$leaseActMAC`, `$leaseServerName`).
- Reuse **DHCP Server Name** and **DHCP Lease Column** so the Option 82 field matches UCRM matching.
- Document ARP `reply-only` as a recommended manual setting.
- Bump `information.version` and pack the zip.
