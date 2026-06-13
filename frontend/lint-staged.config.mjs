/**
 * lint-staged 配置
 *
 * 解决 src/router/elegant/ 下文件被 .oxfmtrc.json 忽略后，
 * 当所有暂存文件都在该目录时 oxfmt 报 "no target files" 而失败的场景。
 *
 * - eslint / oxlint 仍对所有 *.{ts,tsx,vue} 生效。
 * - oxfmt 仅当存在可格式化文件（非 src/router/elegant/）时才加入队列。
 */
export default {
  '*.{ts,tsx,vue}': files => {
    const commands = ['eslint --fix', 'oxlint --fix'];
    const hasFormattable = files.some(f => !f.replace(/\\/g, '/').includes('src/router/elegant/'));
    if (hasFormattable) commands.push('oxfmt');
    return commands;
  }
};
