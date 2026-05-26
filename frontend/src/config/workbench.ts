export const WORKBENCH_CONFIG = {
  STORAGE_KEYS: {
    LEFT_PANEL_WIDTH: 'workbench-left-panel-width',
  },
  
  GRID_THEME: {
    LIGHT: {
      browserColorScheme: 'light' as const,
      rowBorder: { style: 'dotted' as const, width: 1, color: '#c1ccc7' },
      columnBorder: { style: 'dotted' as const, width: 1, color: '#c1ccc7' },
      rangeSelectionBorderColor: '#2196F3',
      rangeSelectionBorderStyle: 'solid' as const
    },
    DARK: {
      browserColorScheme: 'dark' as const,
      rowBorder: { style: 'dotted' as const, width: 1, color: '#4b5965' },
      columnBorder: { style: 'dotted' as const, width: 1, color: '#4b5965' },
      rangeSelectionBorderColor: '#64B5F6',
      rangeSelectionBorderStyle: 'solid' as const
    }
  },
  
  DEFAULT_STYLES: {
    COLOR_MARK: {
      color: 'red',
      fontWeight: 'bold',
      backgroundColor: '#f7acbc'
    }
  },
  
  CHART: {
    DEFAULT_TYPE: 'line',
    DEFAULT_NAME: '数据图形',
    AXIS_POSITION: {
      LEFT: 'Y轴（左侧）',
      RIGHT: 'Y轴（右侧）'
    },
    CHART_TYPE: {
      BAR: '柱状图',
      LINE: '折线图',
      PIE: '饼图'
    }
  },
  
  COLUMN_IDS: {
    SELECTION: 'ag-Grid-SelectionColumn',
    PREFIX: 'ag-Grid-'
  }
};