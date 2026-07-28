-- ============================================================================
-- 029_sales_role.sql
-- ----------------------------------------------------------------------------
-- Adds the 'sales' role and the dashboard.sales.view permission.
--
-- Scope: sells. Owns clients, quotes, orders and dispatch. Reads stock and
-- client empties so they know what they can promise, and reads invoices so they
-- can see who is overdue before taking another order. No cash handling
-- (record_payment / validate_cash stay with finance), no stock movements, no
-- general accounting.
--
-- Idempotent: safe to re-run.
-- ============================================================================

START TRANSACTION;

INSERT INTO permissions (name, module, description)
VALUES ('dashboard.sales.view', 'dashboard', 'Voir le tableau de bord Ventes')
ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description);

INSERT INTO roles (name) VALUES ('sales')
ON DUPLICATE KEY UPDATE name = VALUES(name);

SET @sales_id = (SELECT id FROM roles WHERE name = 'sales' LIMIT 1);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT @sales_id, p.id
  FROM permissions p
 WHERE @sales_id IS NOT NULL
   AND p.name IN (
        'dashboard.sales.view',
        'crm.clients.view','crm.clients.create','crm.clients.edit','crm.clients.convert',
        'crm.proposals.view','crm.proposals.create',
        'sales.orders.view','sales.orders.create','sales.orders.edit','sales.orders.dispatch',
        'sales.deliveries.view','sales.deliveries.close',
        'inventory.stock.view',
        'operations.empties.view',
        'accounting.invoices.view'
   );

-- The MD should see the new sales dashboard too.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT (SELECT id FROM roles WHERE name = 'admin'), p.id
  FROM permissions p WHERE p.name = 'dashboard.sales.view';

COMMIT;

-- ----------------------------------------------------------------------------
-- VERIFY (expect 16)
-- SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
--  WHERE r.name = 'sales';
--
-- ROLLBACK
-- DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.name='sales';
-- DELETE FROM roles WHERE name='sales';
-- ----------------------------------------------------------------------------
