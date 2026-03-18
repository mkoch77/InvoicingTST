const express = require('express');
const { Pool } = require('pg');
const ExcelJS = require('exceljs');
const path = require('path');

const app = express();
const port = process.env.PORT || 3000;

const pool = new Pool({
  host: process.env.PGHOST || 'localhost',
  port: parseInt(process.env.PGPORT || '5432'),
  user: process.env.PGUSER || 'accounting',
  password: process.env.PGPASSWORD || 'changeme',
  database: process.env.PGDATABASE || 'InvoicingAssets',
});

app.use(express.static(path.join(__dirname, 'public')));

// Available months (for dropdown)
app.get('/api/months', async (req, res) => {
  try {
    const result = await pool.query(`
      SELECT DISTINCT TO_CHAR(exported_at, 'YYYY-MM') AS month
      FROM vm
      ORDER BY month DESC
    `);
    res.json(result.rows.map(r => r.month));
  } catch (err) {
    console.error('GET /api/months error:', err.message);
    res.status(500).json({ error: err.message });
  }
});

// Shared: fetch VM rows from DB
async function fetchVMs(month) {
  let query = `
    SELECT
      v.id,
      v.hostname,
      v.dns_name,
      os.name AS operating_system,
      v.vcpu,
      v.vram_mb,
      v.used_storage_gb,
      v.provisioned_storage_gb,
      ps.name AS power_state,
      v.exported_at,
      ARRAY_AGG(ip.ip_address::TEXT ORDER BY ip.ip_address)
        FILTER (WHERE ip.ip_address IS NOT NULL) AS ip_addresses
    FROM vm v
    LEFT JOIN operating_system os ON os.id = v.operating_system_id
    LEFT JOIN power_state ps ON ps.id = v.power_state_id
    LEFT JOIN vm_ip_address ip ON ip.vm_id = v.id
  `;
  const params = [];

  if (month && /^\d{4}-\d{2}$/.test(month)) {
    query += `
    WHERE v.exported_at >= $1::DATE
      AND v.exported_at < ($1::DATE + INTERVAL '1 month')
    `;
    params.push(`${month}-01`);
  }

  query += `
    GROUP BY v.id, os.name, ps.name
    ORDER BY v.hostname, v.exported_at DESC
  `;

  const result = await pool.query(query, params);
  return result.rows;
}

// VM data, optionally filtered by month
app.get('/api/vms', async (req, res) => {
  try {
    const rows = await fetchVMs(req.query.month);
    res.json(rows);
  } catch (err) {
    console.error('GET /api/vms error:', err.message);
    res.status(500).json({ error: err.message });
  }
});

// Excel export — same format as PowerShell Export-ToExcel
app.get('/api/vms/export', async (req, res) => {
  try {
    const month = req.query.month;
    const rows = await fetchVMs(month);

    const wb = new ExcelJS.Workbook();
    const ws = wb.addWorksheet('Virtual Machines');

    // Columns matching the PowerShell export exactly
    const columns = [
      { header: 'Hostname',                 key: 'hostname',                 width: 25 },
      { header: 'DNS Name',                 key: 'dns_name',                 width: 30 },
      { header: 'IP Address',               key: 'ip_address',               width: 20 },
      { header: 'Operating System',         key: 'operating_system',         width: 30 },
      { header: 'vCPU',                     key: 'vcpu',                     width: 8  },
      { header: 'vRAM (MB)',                key: 'vram_mb',                  width: 12 },
      { header: 'Used Storage (GB)',        key: 'used_storage_gb',          width: 18 },
      { header: 'Provisioned Storage (GB)', key: 'provisioned_storage_gb',   width: 22 },
      { header: 'Power State',              key: 'power_state',              width: 14 },
      { header: 'Duplicate',                key: 'duplicate',                width: 12 },
    ];
    ws.columns = columns;

    // Detect duplicate hostnames
    const hostCount = {};
    for (const r of rows) {
      const h = (r.hostname || '').toLowerCase();
      hostCount[h] = (hostCount[h] || 0) + 1;
    }
    const dupeNames = new Set(Object.keys(hostCount).filter(h => hostCount[h] > 1));

    // Add data rows
    for (const r of rows) {
      const ips = (r.ip_addresses || []).join(', ');
      const isDupe = dupeNames.has((r.hostname || '').toLowerCase());
      ws.addRow({
        hostname: r.hostname || '',
        dns_name: r.dns_name || '',
        ip_address: ips,
        operating_system: r.operating_system || '',
        vcpu: r.vcpu ?? 0,
        vram_mb: r.vram_mb ?? 0,
        used_storage_gb: r.used_storage_gb ?? 0,
        provisioned_storage_gb: r.provisioned_storage_gb ?? 0,
        power_state: r.power_state || '',
        duplicate: isDupe ? 'Yes' : '',
      });
    }

    // IP Address column as text (prevent number conversion)
    ws.getColumn('ip_address').numFmt = '@';

    // Style header row: bold, white on dark background (like TableStyle Medium2)
    const headerRow = ws.getRow(1);
    headerRow.font = { bold: true, color: { argb: 'FFFFFFFF' } };
    headerRow.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF4472C4' } };
    headerRow.alignment = { vertical: 'middle' };

    // Freeze top row
    ws.views = [{ state: 'frozen', ySplit: 1 }];

    // AutoFilter over all columns
    ws.autoFilter = {
      from: { row: 1, column: 1 },
      to: { row: rows.length + 1, column: columns.length },
    };

    // Highlight duplicate rows yellow
    for (let i = 2; i <= rows.length + 1; i++) {
      const row = ws.getRow(i);
      if (row.getCell('duplicate').value === 'Yes') {
        row.eachCell(cell => {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFFFFF00' } };
        });
      }
    }

    // Send file
    const dateStr = month || new Date().toISOString().slice(0, 7);
    const filename = `VeeamOne_VMs_${dateStr}.xlsx`;

    res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);

    await wb.xlsx.write(res);
    res.end();
  } catch (err) {
    console.error('GET /api/vms/export error:', err.message);
    res.status(500).json({ error: err.message });
  }
});

// Wait for DB before starting
async function start() {
  for (let i = 0; i < 15; i++) {
    try {
      await pool.query('SELECT 1');
      console.log('Database connected.');
      break;
    } catch {
      console.log(`Waiting for database... (${i + 1}/15)`);
      await new Promise(r => setTimeout(r, 2000));
    }
  }
  app.listen(port, () => console.log(`VM Viewer running on http://localhost:${port}`));
}

start();
