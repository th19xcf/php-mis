import process from 'node:process';
import { URL, fileURLToPath } from 'node:url';
import { defineConfig, loadEnv, type Plugin } from 'vite';
import { setupVitePlugins } from './build/plugins';
import { createViteProxy, getBuildTime } from './build/config';

function dropConsolePlugin(): Plugin {
  return {
    name: 'drop-console-in-prod',
    apply: 'build',
    async generateBundle(_options, bundle) {
      const { transform: esbuildTransform } = await import('esbuild');
      for (const fileName of Object.keys(bundle)) {
        const file = bundle[fileName];
        if (file.type !== 'chunk') continue;
        if (!file.code) continue;
        try {
          const { code } = await esbuildTransform(file.code, {
            drop: ['debugger', 'console'],
            loader: 'js',
            minify: false
          });
          file.code = code;
        } catch (err) {
          this.warn(`drop-console-in-prod: failed to transform ${fileName}: ${(err as Error).message}`);
        }
      }
    }
  };
}

export default defineConfig(configEnv => {
  const viteEnv = loadEnv(configEnv.mode, process.cwd()) as unknown as Env.ImportMeta;

  const buildTime = getBuildTime();

  const enableProxy = configEnv.command === 'serve' && !configEnv.isPreview;

  return {
    base: viteEnv.VITE_BASE_URL,
    resolve: {
      alias: {
        '~': fileURLToPath(new URL('./', import.meta.url)),
        '@': fileURLToPath(new URL('./src', import.meta.url))
      }
    },
    css: {
      preprocessorOptions: {
        scss: {
          api: 'modern-compiler',
          additionalData: `@use "@/styles/scss/global.scss" as *;`
        }
      }
    },
    plugins: [...setupVitePlugins(viteEnv, buildTime), dropConsolePlugin()],
    define: {
      BUILD_TIME: JSON.stringify(buildTime)
    },
    server: {
      host: '0.0.0.0',
      port: 9527,
      open: true,
      proxy: createViteProxy(viteEnv, enableProxy)
    },
    preview: {
      port: 9725
    },
    build: {
      reportCompressedSize: false,
      sourcemap: viteEnv.VITE_SOURCE_MAP === 'Y',
      commonjsOptions: {
        ignoreTryCatch: false
      },
      // 显式分包：将大体积第三方依赖单独打成 vendor chunk，
      // 避免被入口同步加载（ag-grid/echarts 等仅工作台/图表页用到）
      // 并提升浏览器缓存命中率（vendor chunk 内容稳定，业务变更不失效）
      // 注意：rolldown（Vite 7+ 默认）的 manualChunks 只支持函数形式
      rollupOptions: {
        output: {
          manualChunks(id) {
            if (id.includes('node_modules')) {
              if (/[\\/]node_modules[\\/](vue|vue-router|pinia|vue-i18n)[\\/]/.test(id)) return 'vue-vendor';
              if (/[\\/]node_modules[\\/]naive-ui[\\/]/.test(id)) return 'naive-vendor';
              if (/[\\/]node_modules[\\/](ag-grid-community|ag-grid-vue3|@ag-grid-community)[\\/]/.test(id)) return 'ag-grid-vendor';
              if (/[\\/]node_modules[\\/]echarts[\\/]/.test(id)) return 'echarts-vendor';
              if (/[\\/]node_modules[\\/]@e965[\\/]xlsx[\\/]/.test(id)) return 'xlsx-vendor';
              if (/[\\/]node_modules[\\/](dayjs|@vueuse|@better-scroll|vue-draggable-plus|@iconify)[\\/]/.test(id)) return 'utils-vendor';
            }
          }
        }
      },
      // 过滤 modulepreload：ag-grid-vendor(1.6MB)/xlsx-vendor(684KB) 仅工作台/导出时用，
      // 不应首屏预加载；保留 vue/naive/echarts/utils vendor 预加载（首屏或首页图表即需）
      modulePreload: {
        polyfill: true,
        resolveDependencies(_filename, deps) {
          return deps.filter(dep => !/ag-grid-vendor|xlsx-vendor/.test(dep));
        }
      }
    }
  };
});
