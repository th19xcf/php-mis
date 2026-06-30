import { addAPIProvider, addCollection } from '@iconify/vue';
import offlineIcons from './offline-icons-data.json';

export function setupIconifyOffline() {
  const { VITE_ICONIFY_URL } = import.meta.env;

  if (VITE_ICONIFY_URL) {
    addAPIProvider('', { resources: [VITE_ICONIFY_URL] });
  }

  Object.values(offlineIcons).forEach(iconSet => {
    addCollection(iconSet as any);
  });
}
