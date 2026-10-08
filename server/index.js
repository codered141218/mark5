'use strict';
process.env.TZ = process.env.TZ || 'Asia/Manila';

const path = require('path');
const fs = require('fs');
const express = require('express');
const db = require('./db');

db.open();
const { seedBase, seedSampleData } = require('./seed');
seedBase();
if (process.env.SEED_SAMPLE !== '0') seedSampleData();

const backup = require('./backup');
const { authenticate } = require('./auth');

const app = express();
app.disable('x-powered-by');
app.use(express.json({ limit: '25mb' }));

app.use('/api', require('./routes/admin'));
app.use('/api/inventory', authenticate, require('./routes/inventory'));
app.use('/api/pos', authenticate, require('./routes/pos'));
app.use('/api/finance', authenticate, require('./routes/finance'));
app.use('/api/reports', authenticate, require('./routes/reports'));
app.use('/api', (req, res) => res.status(404).json({ error: 'Not found' }));

const dist = path.join(__dirname, '..', 'client', 'dist');
if (fs.existsSync(dist)) {
  app.use(express.static(dist));
  app.get('*', (req, res) => res.sendFile(path.join(dist, 'index.html')));
}

// eslint-disable-next-line no-unused-vars
app.use((err, req, res, next) => {
  const status = err.status || 500;
  if (status >= 500) console.error(err);
  res.status(status).json({ error: status >= 500 && !err.status ? `Server error: ${err.message}` : err.message });
});

const PORT = Number(process.env.PORT) || 3000;
if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`Mark5 Restaurant Suite running on http://localhost:${PORT}`);
  });
  try { backup.autoBackup(); } catch (e) { console.error('Auto backup failed:', e.message); }
  setInterval(() => { try { backup.autoBackup(); } catch (e) { console.error('Auto backup failed:', e.message); } }, 60 * 60 * 1000).unref();
}

module.exports = app;
