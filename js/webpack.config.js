const config = require('flarum-webpack-config');

const NS = 'ernestdefoe-chronicle';

/*
 * Lazy chunks need ids no other extension can have.
 *
 * Flarum's registry finds a chunk's URL by its id alone, and every extension's
 * chunks report to one shared array. Webpack's default ids are 3-digit hashes,
 * so two extensions can share one: the wrong file is fetched, or the chunk
 * counts as already loaded, and the page dies with "r[t] is not a function".
 * So: ids prefixed with our extension id, and our own chunk-loading global.
 */
class NamespacedChunkIds {
  apply(compiler) {
    compiler.hooks.compilation.tap('NamespacedChunkIds', (compilation) => {
      compilation.hooks.chunkIds.tap('NamespacedChunkIds', (chunks) => {
        for (const chunk of chunks) {
          if (chunk.id === null) {
            chunk.id = `${NS}:${chunk.name || chunk.debugId}`;
            chunk.ids = [chunk.id];
          }
        }
      });
    });
  }
}

function namespaced(base) {
  base.optimization = { ...base.optimization, chunkIds: false };
  base.output = { ...base.output, chunkLoadingGlobal: 'webpackChunk_ernestdefoe_chronicle' };
  base.plugins = [...(base.plugins || []), new NamespacedChunkIds()];
  return base;
}

module.exports = namespaced(config({ useExtensions: [] }));
