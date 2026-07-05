import { request } from '../request';

export interface MatchColumn {
  字段名: string;
  字段别名: string;
  可匹配?: string;
  [key: string]: any;
}

export interface MatchConfig {
  queryModule: string;
  mode: string;
  fieldModule: string;
  queryTable: string;
  dataTable: string;
  dataModel: string;
  queryWhere: string;
  queryGroup: string;
  queryOrder: string;
  resultCount: number;
  commentModule: string;
  chartModule: string;
  tableStyle: string;
}

export interface MatchMeta {
  functionCode: string;
  title: string;
  menu1: string;
  menu2: string;
  module: string;
  params: string;
  aModule: string;
  bModule: string;
  aConfig: MatchConfig;
  bConfig: MatchConfig;
  aColumns: MatchColumn[];
  bColumns: MatchColumn[];
  aMatchCols: { key: string; label: string; amount: string; target: string };
  bMatchCols: { key: string; label: string; amount: string; target: string };
}

export interface MatchPageResult {
  meta: MatchMeta;
  aData: { rows: any[]; total: number };
  bData: { rows: any[]; total: number };
}

export interface MatchBuildRelationParams {
  aModule: string;
  bModule: string;
  aKeys: string[];
  bKeys: string[];
}

export interface MatchRevokeRelationParams {
  aModule: string;
  bModule: string;
  aKeys: string[];
  bKeys: string[];
  mode?: 'specific' | 'all';
}

export interface MatchRelationResult {
  aKeys: string[];
  bKeys: string[];
  count?: number;
  mode?: string;
}

export function fetchMatchPage(functionCode: string) {
  return request<MatchPageResult>({
    url: `/match/page/${functionCode}`,
    method: 'get'
  });
}

export function buildMatchRelation(params: MatchBuildRelationParams) {
  return request<MatchRelationResult>({
    url: '/match/buildRelation',
    method: 'post',
    data: params
  });
}

export function revokeMatchRelation(params: MatchRevokeRelationParams) {
  return request<MatchRelationResult>({
    url: '/match/revokeRelation',
    method: 'post',
    data: params
  });
}
