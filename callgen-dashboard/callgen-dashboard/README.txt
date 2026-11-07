CALLGEN WEB DASHBOARD - INSTALL NOTES

This is a starter package, not a drop-in replacement until tested against your server's real CDR/channel format.

Features: destination host, monthly minute limit, concurrent call limit, call duration setting, service start/stop, current month usage and active call display.

1. Back up your current generator. Do not run the old manually launched script and this systemd service simultaneously.
2. Copy public/ to /var/www/html/callgen/
3. Copy bin/asterisk_callgen.sh to /usr/local/bin/asterisk_callgen.sh
4. Copy bin/callgen-control to /usr/local/sbin/callgen-control
5. Copy systemd/asterisk-callgen-cuba.service to /etc/systemd/system/
6. Create /etc/callgen-cuba.json:
   {"target_ip":"202.125.153.97","monthly_limit":100000,"max_channels":150,"call_duration":600}
   chown root:asterisk /etc/callgen-cuba.json && chmod 640 /etc/callgen-cuba.json
7. chmod 750 /usr/local/bin/asterisk_callgen.sh /usr/local/sbin/callgen-control
8. Install sudoers/callgen-dashboard as /etc/sudoers.d/callgen-dashboard.
   Validate: visudo -cf /etc/sudoers.d/callgen-dashboard
   Change apache to your actual PHP web user (often apache on Rocky Linux).
9. Set CALLGEN_DASHBOARD_PASSWORD in the Apache/PHP environment; restart web service. Do not put password in web root.
10. Serve the page over HTTPS and firewall-restrict it to your admin VPN/IP.
11. Test: /usr/local/sbin/callgen-control status
    systemctl daemon-reload
    systemctl enable asterisk-callgen-cuba.service
    Start from the dashboard after confirming all settings.

CRITICAL:
- Add an enforced 600-second absolute call timeout to the correct testcall2 dialplan. Call-file WaitTime is only answer wait; it is not connected call duration.
- This starter expects standard Master.csv layout: channel column 5, dstchannel 6, start 9, billsec 13, disposition 14 (zero-based Python indexes).
- It matches destination IP as text in channel/dstchannel. Verify your real CDR rows: with SIP dial string SIP/user@IP, this may match; if your trunk naming differs, adjust matching logic.
- CDR rotation/truncation must be accounted for by reading archived monthly CDRs too.
- The dashboard's monthly usage is rounded for display; limit decision uses seconds.
- Active calls are not forcibly disconnected at limit. It stops placing new calls.
- The dashboard login is not a substitute for HTTPS, VPN/firewall restriction, and server security review.
- The current implementation reserves call_duration seconds per active/pending call as a conservative estimate; actual calls longer than the configured cap can still overshoot unless dialplan enforcement works.
- Verify actual concurrent channel counting for your Asterisk version and trunk type before production use.
