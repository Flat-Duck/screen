# Account Compromise Response

1. Verify the report through an approved ownership channel without requesting passwords, recovery
   codes, access tokens, or screenshots containing secrets. Treat changes to email, social links,
   2FA, device sessions, and unusual posting/messaging as evidence—not proof by themselves.
2. Contain through audited admin controls: suspend access when risk is credible and revoke active
   user sessions/device associations through supported actions. Do not alter database tokens by hand
   unless the incident commander authorizes an emergency procedure.
3. Preserve security audit logs, device-session history, email-change outbox records, relevant
   telemetry IDs, and timestamps. Avoid copying message or screenshot contents unless essential.
4. Restore ownership only after identity verification. Require new authentication credentials,
   regenerate 2FA recovery codes, review connected social accounts, and confirm the destination email.
5. Review attacker actions: posts, deletes/restores, archives, follows, blocks, messages, reports,
   settings, push tokens, and data-export/deletion requests. Reverse only actions the legitimate user
   confirms; do not silently restore harmful content.
6. Notify the user through a verified channel with the containment and recovery facts. Escalate any
   wider token leakage, staff access, or repeated fingerprint to the security incident process.

Close after all sessions are rotated, ownership is confirmed, harmful actions are handled, audit
evidence is retained under policy, and root-cause/remediation work is assigned.
