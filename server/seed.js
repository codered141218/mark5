'use strict';
const bcrypt = require('bcryptjs');
const db = require('./db');
const { now, setSetting, getSetting, nextNo } = require('./util');
const { DEFAULT_ROLES } = require('./permissions');
const { seedAccounts } = require('./gl');

const DEFAULT_SETTINGS = {
  business_name: 'My Restaurant',
  business_address: 'Manila, Philippines',
  business_tin: '000-000-000-00000',
  business_phone: '',
  receipt_title: 'ORDER RECEIPT',
  receipt_footer: 'Thank you, please come again!',
  receipt_prefix: 'OR',
  require_payment_ref: '0',
  vat_registered: '1',
  vat_rate: '12',
  sc_discount_rate: '20',
  service_charge_rate: '0',
  service_charge_dine_in_only: '1',
  allow_negative_stock: '1',
  auto_backup: '1',
  backup_retention: '30',
  pos_require_open_day: '1',
};

function seedBase() {
  db.tx(() => {
    for (const [k, v] of Object.entries(DEFAULT_SETTINGS)) {
      if (getSetting(k) === null) setSetting(k, v);
    }
    seedAccounts();

    for (const r of DEFAULT_ROLES) {
      if (!db.get('SELECT id FROM roles WHERE name = ?', r.name)) {
        db.insert('roles', { name: r.name, description: r.description, permissions: JSON.stringify(r.permissions), is_system: r.is_system || 0 });
      }
    }

    if (!db.get('SELECT id FROM users LIMIT 1')) {
      const adminRole = db.get("SELECT id FROM roles WHERE name = 'Administrator'");
      db.insert('users', {
        username: 'admin', full_name: 'System Administrator',
        password_hash: bcrypt.hashSync('admin123', 10), pin_hash: bcrypt.hashSync('1234', 10),
        role_id: adminRole.id, active: 1, created_at: now(),
      });
    }

    const uoms = [
      ['Piece', 'pc'], ['Kilogram', 'kg'], ['Gram', 'g'], ['Liter', 'L'], ['Milliliter', 'ml'],
      ['Pack', 'pack'], ['Bottle', 'btl'], ['Can', 'can'], ['Case', 'case'], ['Sack', 'sack'],
      ['Tray', 'tray'], ['Box', 'box'], ['Gallon', 'gal'], ['Serving', 'srv'], ['Dozen', 'doz'],
    ];
    for (const [name, abbr] of uoms) {
      if (!db.get('SELECT id FROM uoms WHERE abbr = ?', abbr)) db.insert('uoms', { name, abbr });
    }
    const u = (abbr) => db.value('SELECT id FROM uoms WHERE abbr = ?', abbr);
    const convs = [['kg', 'g', 1000], ['L', 'ml', 1000], ['gal', 'L', 3.785], ['doz', 'pc', 12]];
    for (const [f, t, factor] of convs) {
      if (!db.get('SELECT id FROM uom_conversions WHERE from_uom_id = ? AND to_uom_id = ?', u(f), u(t))) {
        db.insert('uom_conversions', { from_uom_id: u(f), to_uom_id: u(t), factor });
      }
    }
  });
}

// Sample Filipino menu with recipes so a new install has something to explore.
function seedSampleData() {
  if (db.get('SELECT id FROM items LIMIT 1')) return;
  db.tx(() => {
    const u = (abbr) => db.value('SELECT id FROM uoms WHERE abbr = ?', abbr);
    const cat = (name, kind, color, sort) => db.insert('categories', { name, kind, color, sort_order: sort, active: 1 });
    const cats = {
      rice: cat('Rice Meals', 'menu', '#e8590c', 1),
      ulam: cat('Ulam / Viands', 'menu', '#c2255c', 2),
      noodles: cat('Noodles', 'menu', '#f59f00', 3),
      drinks: cat('Beverages', 'menu', '#1c7ed6', 4),
      dessert: cat('Desserts', 'menu', '#7048e8', 5),
      meat: cat('Meat & Poultry', 'inventory', '#868e96', 10),
      dry: cat('Dry Goods & Condiments', 'inventory', '#868e96', 11),
      produce: cat('Produce', 'inventory', '#868e96', 12),
    };
    let skuN = 1;
    const item = (o) => db.insert('items', {
      sku: o.sku || `IT${String(skuN++).padStart(4, '0')}`, name: o.name, category_id: o.cat, item_type: o.type,
      base_uom_id: u(o.uom), price: o.price || 0, avg_cost: o.cost || 0, last_cost: o.cost || 0,
      reorder_point: o.rop || 0, reorder_qty: o.roq || 0, sellable: o.sellable ? 1 : 0, active: 1,
      color: o.color || null, created_at: now(), updated_at: now(),
    });
    const raw = (name, catId, uom, cost, rop, roq) => item({ name, cat: catId, type: 'raw', uom, cost, rop, roq });
    const R = {
      chicken: raw('Chicken (whole cut)', cats.meat, 'kg', 190, 5, 10),
      pork: raw('Pork Liempo', cats.meat, 'kg', 320, 5, 10),
      porkface: raw('Pork Maskara (for Sisig)', cats.meat, 'kg', 180, 3, 5),
      rice: raw('Rice (Dinorado)', cats.dry, 'kg', 55, 25, 50),
      soy: raw('Soy Sauce', cats.dry, 'L', 60, 2, 4),
      vinegar: raw('Cane Vinegar', cats.dry, 'L', 45, 2, 4),
      garlic: raw('Garlic', cats.produce, 'kg', 140, 1, 2),
      onion: raw('Red Onion', cats.produce, 'kg', 160, 1, 2),
      oil: raw('Cooking Oil', cats.dry, 'L', 95, 3, 6),
      sinigangmix: raw('Sinigang Mix', cats.dry, 'pack', 22, 10, 24),
      kangkong: raw('Kangkong', cats.produce, 'kg', 60, 1, 3),
      canton: raw('Pancit Canton Noodles', cats.dry, 'pack', 48, 5, 10),
      egg: raw('Egg', cats.produce, 'pc', 8, 30, 60),
      calamansi: raw('Calamansi', cats.produce, 'kg', 80, 1, 2),
      sugar: raw('Sugar', cats.dry, 'kg', 75, 2, 5),
      icedteamix: raw('Iced Tea Powder', cats.dry, 'pack', 35, 3, 6),
      halo: raw('Halo-halo Mix-ins', cats.dry, 'kg', 250, 1, 2),
      milk: raw('Evaporated Milk', cats.dry, 'can', 32, 6, 12),
    };
    // Purchase units
    db.insert('item_uoms', { item_id: R.rice, uom_id: u('sack'), factor: 50 });
    db.insert('item_uoms', { item_id: R.egg, uom_id: u('tray'), factor: 30 });
    db.insert('item_uoms', { item_id: R.oil, uom_id: u('gal'), factor: 3.785 });
    db.insert('item_uoms', { item_id: R.milk, uom_id: u('case'), factor: 48 });

    const coke = item({ name: 'Coke in Can', cat: cats.drinks, type: 'retail', uom: 'can', price: 65, cost: 38, rop: 24, roq: 48, sellable: true });
    const smb = item({ name: 'San Miguel Pale Pilsen', cat: cats.drinks, type: 'retail', uom: 'btl', price: 85, cost: 52, rop: 24, roq: 48, sellable: true });
    const water = item({ name: 'Bottled Water', cat: cats.drinks, type: 'retail', uom: 'btl', price: 30, cost: 12, rop: 24, roq: 48, sellable: true });
    db.insert('item_uoms', { item_id: coke, uom_id: u('case'), factor: 24 });
    db.insert('item_uoms', { item_id: smb, uom_id: u('case'), factor: 24 });
    db.insert('item_uoms', { item_id: water, uom_id: u('case'), factor: 24 });

    const menu = (name, catId, price) => item({ name, cat: catId, type: 'composite', uom: 'srv', price, sellable: true });
    const comp = (parent, child, qty, uom) => db.insert('item_components', { parent_id: parent, component_id: child, qty, uom_id: u(uom) });

    const plainRice = menu('Plain Rice', cats.rice, 25);
    comp(plainRice, R.rice, 120, 'g');
    const garlicRice = menu('Garlic Rice', cats.rice, 40);
    comp(garlicRice, R.rice, 120, 'g'); comp(garlicRice, R.garlic, 10, 'g'); comp(garlicRice, R.oil, 10, 'ml');

    const adobo = menu('Chicken Adobo', cats.ulam, 185);
    comp(adobo, R.chicken, 250, 'g'); comp(adobo, R.soy, 40, 'ml'); comp(adobo, R.vinegar, 30, 'ml'); comp(adobo, R.garlic, 15, 'g'); comp(adobo, R.oil, 15, 'ml');
    const sinigang = menu('Sinigang na Baboy', cats.ulam, 265);
    comp(sinigang, R.pork, 250, 'g'); comp(sinigang, R.sinigangmix, 1, 'pack'); comp(sinigang, R.kangkong, 100, 'g'); comp(sinigang, R.onion, 30, 'g');
    const sisig = menu('Sizzling Pork Sisig', cats.ulam, 225);
    comp(sisig, R.porkface, 200, 'g'); comp(sisig, R.onion, 40, 'g'); comp(sisig, R.calamansi, 20, 'g'); comp(sisig, R.egg, 1, 'pc'); comp(sisig, R.oil, 10, 'ml');
    const adoboMeal = menu('Adobo Rice Meal', cats.rice, 199);
    comp(adoboMeal, adobo, 1, 'srv'); comp(adoboMeal, plainRice, 1, 'srv');
    const sisigMeal = menu('Sisig Rice Meal', cats.rice, 219);
    comp(sisigMeal, sisig, 1, 'srv'); comp(sisigMeal, garlicRice, 1, 'srv');

    const canton = menu('Pancit Canton (Good for 3)', cats.noodles, 280);
    comp(canton, R.canton, 1, 'pack'); comp(canton, R.chicken, 150, 'g'); comp(canton, R.soy, 30, 'ml'); comp(canton, R.onion, 30, 'g'); comp(canton, R.garlic, 10, 'g');

    const icedtea = menu('House Iced Tea', cats.drinks, 55);
    comp(icedtea, R.icedteamix, 0.1, 'pack'); comp(icedtea, R.sugar, 15, 'g'); comp(icedtea, R.calamansi, 10, 'g');
    const halohalo = menu('Halo-halo Special', cats.dessert, 140);
    comp(halohalo, R.halo, 120, 'g'); comp(halohalo, R.milk, 0.25, 'can'); comp(halohalo, R.sugar, 15, 'g');
    item({ name: 'Extra Egg', cat: cats.ulam, type: 'composite', uom: 'srv', price: 20, sellable: true });
    comp(db.value("SELECT id FROM items WHERE name = 'Extra Egg'"), R.egg, 1, 'pc');
    item({ name: 'Corkage Fee', cat: cats.drinks, type: 'non_inventory', uom: 'pc', price: 150, sellable: true });

    const areas = [['Main Hall', 8], ['Al Fresco', 4], ['VIP Room', 2]];
    let n = 1;
    for (const [area, count] of areas) {
      for (let i = 1; i <= count; i++) {
        db.insert('dining_tables', { name: area === 'VIP Room' ? `VIP ${i}` : area === 'Al Fresco' ? `AF${i}` : `T${n++}`, area, seats: area === 'VIP Room' ? 10 : 4, sort_order: i, active: 1 });
      }
    }
    db.insert('suppliers', { name: 'Metro Meat Supply', contact_person: 'Mang Jun', phone: '0917-000-0000', terms_days: 15, active: 1 });
    db.insert('suppliers', { name: 'Divisoria Dry Goods Trading', phone: '0918-000-0000', terms_days: 0, active: 1 });
    db.insert('customers', { name: 'ABC Corporation (Charge Account)', terms_days: 30, credit_limit: 50000, active: 1 });
    db.insert('employees', { emp_no: nextNo('EMP', 'EMP', 3), full_name: 'Juan Dela Cruz', position: 'Cook', department: 'Kitchen', active: 1, date_hired: '2025-01-15' });
    db.insert('employees', { emp_no: nextNo('EMP', 'EMP', 3), full_name: 'Maria Santos', position: 'Cashier', department: 'Front of House', active: 1, date_hired: '2025-03-01' });
  });
}

module.exports = { seedBase, seedSampleData, DEFAULT_SETTINGS };
