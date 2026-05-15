import { defineConfig } from 'vite';
import { svelte, vitePreprocess } from '@sveltejs/vite-plugin-svelte';
import { existsSync, lstatSync, readlinkSync, rmSync, symlinkSync, writeFileSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function hasPathEntry(entryPath) {
    try {
        lstatSync(entryPath);
        return true;
    } catch {
        return false;
    }
}

function isSymlinkToExpectedTarget(linkPath, expectedTargetPath) {
    try {
        const linkedTarget = readlinkSync(linkPath);
        const resolvedTarget = path.resolve(path.dirname(linkPath), linkedTarget);

        return resolvedTarget === expectedTargetPath;
    } catch {
        return false;
    }
}

export default defineConfig(() => {
    const configuredMagentoRoot = process.env.MAGENTO_ROOT;
    const defaultMagentoRoot = '/var/www/html/current/src';
    const magentoRoot = configuredMagentoRoot
        ? path.resolve(__dirname, configuredMagentoRoot)
        : defaultMagentoRoot;
    const themeVendor = process.env.THEME_VENDOR || 'BA';
    const themeName = process.env.THEME_NAME || 'Workday_Theme';
    const themeLocale = process.env.THEME_LOCALE || 'en_US';
    const scdRoot = process.env.SCD_ROOT
        ? path.resolve(process.env.SCD_ROOT)
        : path.join(magentoRoot, 'pub', 'static', 'frontend', themeVendor, themeName, themeLocale);
    const inputEntry = path.join(scdRoot, 'BA_Svelte', 'svelte-src', 'src', 'main.js');
    const outputDirectory = path.join(scdRoot, 'js', 'dist');
    const scdPackageJson = path.join(scdRoot, 'package.json');
    const scdNodeModules = path.join(scdRoot, 'node_modules');
    const sourceNodeModules = path.join(__dirname, 'node_modules');

    if (!existsSync(scdRoot)) {
        throw new Error(
            `SCD root not found at "${scdRoot}". Run "bin/magento setup:static-content:deploy" first or set SCD_ROOT.`
        );
    }

    if (!existsSync(scdPackageJson)) {
        writeFileSync(
            scdPackageJson,
            JSON.stringify({
                name: 'magento-scd-root',
                private: true,
                type: 'module',
            }, null, 2)
        );
    }

    if (!existsSync(scdNodeModules) && hasPathEntry(scdNodeModules)) {
        rmSync(scdNodeModules, { force: true, recursive: true });
    }

    if (hasPathEntry(scdNodeModules) && isSymlinkToExpectedTarget(scdNodeModules, sourceNodeModules) === false) {
        const stats = lstatSync(scdNodeModules);
        if (stats.isSymbolicLink()) {
            rmSync(scdNodeModules, { force: true, recursive: true });
        }
    }

    if (!hasPathEntry(scdNodeModules) && existsSync(sourceNodeModules)) {
        symlinkSync(sourceNodeModules, scdNodeModules, 'dir');
    }

    if (!existsSync(inputEntry)) {
        throw new Error(
            `Svelte entry not found at "${inputEntry}". Static content deploy must copy BA_Svelte assets before building.`
        );
    }

    if (!existsSync(path.join(scdRoot, 'BA_Svelte'))) {
        throw new Error(
            `BA_Svelte assets were not found in "${scdRoot}". Static content deploy must include the BA_Svelte module first.`
        );
    }

    return {
        plugins: [svelte()],
        root: scdRoot,
        base: './',
        publicDir: false,
        preprocess: vitePreprocess({ script: true }),
        resolve: {
            alias: {
                '@modules': scdRoot,
            },
            dedupe: ['svelte'],
        },
        build: {
            cssCodeSplit: false,
            emptyOutDir: true,
            manifest: false,
            outDir: outputDirectory,
            rollupOptions: {
                input: inputEntry,
                output: {
                    assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css') ? 'svelte-app.css' : '[name][extname]',
                    entryFileNames: 'svelte-app.js',
                    inlineDynamicImports: true,
                },
            },
        },
    };
});
