# prestagedSWAP Security Policies
- All API keys stored in CORE_CONFIG only; never hardcoded.
- Database connections must use PDO with prepared statements.
- Multi-factor authentication required for ADMIN access.
- Logs and audit trails must be immutable.
- Swap PINs and voucher codes must be encrypted at rest.
- Transactions must use ACID-compliant transactions across DBs.
