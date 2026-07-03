const fs = require('fs');
const path = require('path');

const ICON_SETS = [
  { prefix: 'mdi', jsonPath: require.resolve('@iconify/json/json/mdi.json') },
  { prefix: 'ant-design', jsonPath: require.resolve('@iconify/json/json/ant-design.json') },
  { prefix: 'ph', jsonPath: require.resolve('@iconify/json/json/ph.json') },
  { prefix: 'material-symbols', jsonPath: require.resolve('@iconify/json/json/material-symbols.json') },
  { prefix: 'carbon', jsonPath: require.resolve('@iconify/json/json/carbon.json') },
  { prefix: 'line-md', jsonPath: require.resolve('@iconify/json/json/line-md.json') },
  { prefix: 'majesticons', jsonPath: require.resolve('@iconify/json/json/majesticons.json') }
];

const SCAN_DIRS = ['src'];
const SCAN_EXTENSIONS = ['.vue', '.ts', '.tsx', '.js', '.jsx', '.json'];
const OUTPUT_PATH = path.join(__dirname, '../src/plugins/offline-icons-data.json');

function scanFiles(dir) {
  const results = [];
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === 'dist') continue;
      results.push(...scanFiles(fullPath));
    } else if (SCAN_EXTENSIONS.includes(path.extname(entry.name))) {
      results.push(fullPath);
    }
  }
  return results;
}

function extractIcons(files) {
  const iconsBySet = {};
  const iconPatterns = ICON_SETS.map(set => ({
    prefix: set.prefix,
    regex: new RegExp(`${set.prefix.replace('-', '\\-')}:[a-z0-9\\-]+`, 'gi')
  }));

  for (const file of files) {
    let content;
    try {
      content = fs.readFileSync(file, 'utf-8');
    } catch {
      continue;
    }

    for (const { prefix, regex } of iconPatterns) {
      const matches = content.match(regex);
      if (matches) {
        if (!iconsBySet[prefix]) iconsBySet[prefix] = new Set();
        for (const match of matches) {
          const iconName = match.split(':')[1].toLowerCase();
          iconsBySet[prefix].add(iconName);
        }
      }
    }
  }

  return iconsBySet;
}

function buildIconSet(iconSetConfig, iconNames) {
  const fullSet = JSON.parse(fs.readFileSync(iconSetConfig.jsonPath, 'utf-8'));
  const icons = {};

  for (const name of iconNames) {
    if (fullSet.icons[name]) {
      icons[name] = fullSet.icons[name];
    } else {
      console.warn(`  ⚠️  图标未找到: ${iconSetConfig.prefix}:${name}`);
    }
  }

  return {
    prefix: fullSet.prefix,
    icons,
    width: fullSet.width,
    height: fullSet.height
  };
}

function main() {
  console.log('🔍  扫描项目中的图标...');

  const files = [];
  for (const dir of SCAN_DIRS) {
    const fullDir = path.join(__dirname, '..', dir);
    if (fs.existsSync(fullDir)) {
      files.push(...scanFiles(fullDir));
    }
  }

  console.log(`   扫描了 ${files.length} 个文件`);

  const iconsBySet = extractIcons(files);

  let totalIcons = 0;
  const result = {};

  for (const setConfig of ICON_SETS) {
    const iconNames = iconsBySet[setConfig.prefix];
    if (!iconNames || iconNames.size === 0) continue;

    console.log(`\n📦 ${setConfig.prefix}: ${iconNames.size} 个图标`);
    totalIcons += iconNames.size;
    result[setConfig.prefix] = buildIconSet(setConfig, iconNames);
  }

  const jsonStr = JSON.stringify(result);
  fs.writeFileSync(OUTPUT_PATH, jsonStr);

  const sizeKB = (Buffer.byteLength(jsonStr, 'utf-8') / 1024).toFixed(1);
  console.log(`\n✅  完成! 共 ${totalIcons} 个图标, ${sizeKB} KB`);
  console.log(`   输出: ${path.relative(process.cwd(), OUTPUT_PATH)}`);
}

main();
