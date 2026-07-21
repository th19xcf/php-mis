import process from 'node:process';
import path from 'node:path';
import { promises as fs } from 'node:fs';
import { presetIcons } from 'unocss';
import unocss from 'unocss/vite';

/**
 * 内联实现 @iconify/utils 的 FileSystemIconLoader
 *
 * 原依赖 @iconify/utils 为间接依赖（经 unplugin-icons / unocss 引入），
 * pnpm 11 严格隔离下间接依赖可能未被提升至 node_modules 顶层，
 * 导致 vite 加载配置时 ERR_MODULE_NOT_FOUND。
 * FileSystemIconLoader 逻辑简单（读 SVG 文件 + 可选 transform），
 * 内联后消除对该间接依赖的耦合。
 */
function FileSystemIconLoader(
  dir: string,
  transform?: (svg: string) => string
): (name: string) => Promise<string | undefined> {
  return async (name: string) => {
    const filepath = path.join(dir, `${name}.svg`);
    try {
      const content = await fs.readFile(filepath, 'utf8');
      return transform ? transform(content) : content;
    } catch {
      return undefined;
    }
  };
}

export function setupUnocss(viteEnv: Env.ImportMeta) {
  const { VITE_ICON_PREFIX, VITE_ICON_LOCAL_PREFIX } = viteEnv;

  const localIconPath = path.join(process.cwd(), 'src/assets/svg-icon');

  /** The name of the local icon collection */
  const collectionName = VITE_ICON_LOCAL_PREFIX.replace(`${VITE_ICON_PREFIX}-`, '');

  return unocss({
    presets: [
      presetIcons({
        prefix: `${VITE_ICON_PREFIX}-`,
        scale: 1,
        extraProperties: {
          display: 'inline-block'
        },
        collections: {
          [collectionName]: FileSystemIconLoader(localIconPath, svg =>
            svg.replace(/^<svg\s/, '<svg width="1em" height="1em" ')
          )
        },
        warn: true
      })
    ]
  });
}
