const Database = require('better-sqlite3');
const db = new Database('C:/Users/info/sbn-app/database/sbn.db', { readonly: true });

// Check m7-shell-roota carefully
const row = db.prepare(
  `SELECT slug, diagram_data, start_fret, voicing_category, root_string
   FROM sbn_chord_diagrams WHERE slug = 'm7-shell-roota'`
).get();
console.log('m7-shell-roota:');
const d = JSON.parse(row.diagram_data);
console.log('start_fret:', row.start_fret);
console.log('positions:', JSON.stringify(d.positions));
console.log('muted:', JSON.stringify(d.muted));

// Also check dom7-shell-roota for comparison
const row2 = db.prepare(
  `SELECT slug, diagram_data, start_fret FROM sbn_chord_diagrams WHERE slug = 'dom7-shell-roota'`
).get();
console.log('\ndom7-shell-roota:');
const d2 = JSON.parse(row2.diagram_data);
console.log('start_fret:', row2.start_fret);
console.log('positions:', JSON.stringify(d2.positions));
console.log('muted:', JSON.stringify(d2.muted));
db.close();
