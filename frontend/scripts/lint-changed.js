import { execSync, spawnSync } from 'node:child_process';

const getChangedTsFiles = () => {
  const diffOutput = execSync('git diff --name-only --diff-filter=ACMRT main...HEAD', {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'inherit'],
  });

  return diffOutput
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean)
    .filter((file) => file === 'frontend' || file.startsWith('frontend/'))
    .filter((file) => file.endsWith('.ts') || file.endsWith('.tsx'))
    .filter((file) => !file.includes('/dist/'));
};

const files = getChangedTsFiles().map((file) => (file.startsWith('frontend/') ? file.slice('frontend/'.length) : file));

if (files.length === 0) {
  process.stdout.write('lint:changed: no changed .ts/.tsx files\n');
  process.exit(0);
}

const result = spawnSync('eslint', files, { stdio: 'inherit' });
process.exit(result.status ?? 1);
