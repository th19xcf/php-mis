import type { TreeOption } from 'naive-ui';

export interface TreeNodeData {
  type: string;
  guid?: string;
}

export interface TreeCheckStore {
  setCheckedKeys: (keys: string[]) => void;
  setSelectedGuids: (guids: string[]) => void;
}

export function useTreeCheck<T extends TreeNodeData = TreeNodeData>(
  store: TreeCheckStore
) {
  function handleCheck(keys: string[], optionNodes: (TreeOption | null)[]) {
    const guids: string[] = [];

    function collectPeople(nodes: (TreeOption | null)[]) {
      for (const node of nodes) {
        if (!node) continue;
        const data = node.data as T;
        if (data.type === 'person' && data.guid) {
          if (!guids.includes(data.guid)) {
            guids.push(data.guid);
          }
        }
        if (node.children) {
          collectPeople(node.children);
        }
      }
    }

    for (const key of keys) {
      const node = optionNodes.find(n => n?.key === key);
      if (node) {
        const data = node.data as T;
        if (data.type === 'person' && data.guid) {
          if (!guids.includes(data.guid)) {
            guids.push(data.guid);
          }
        } else if (node.children) {
          collectPeople(node.children);
        }
      }
    }

    store.setCheckedKeys(keys);
    store.setSelectedGuids(guids);
  }

  function handleSelect(keys: string[], optionNodes: (TreeOption | null)[], onSelectPerson?: (guid: string) => void) {
    if (keys.length === 0) return;

    const key = keys[0];
    const node = optionNodes.find(n => n?.key === key);
    if (node) {
      const data = node.data as T;
      if (data.type === 'person' && data.guid && onSelectPerson) {
        onSelectPerson(data.guid);
      }
    }
  }

  return {
    handleCheck,
    handleSelect
  };
}
