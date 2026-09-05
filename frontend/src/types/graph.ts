export interface DbQuery {
  type: 'eloquent' | 'raw'
  model: string
  table: string
  operation: string
}

/** What a cache call does to cache state. `read` covers `remember`, which writes only on a miss. */
export type CacheOperationKind = 'read' | 'write' | 'invalidate' | 'lock'

/** How far `CacheOperation.key` can be trusted; `computed` and `none` both leave it empty. */
export type CacheKeyKind = 'literal' | 'constructed' | 'computed' | 'none'

export interface CacheOperation {
  kind: CacheOperationKind
  /** The cache method as written — `remember`, `forget`, `pull`, or `cache` for the helper. */
  method: string
  key: string
  keyKind: CacheKeyKind
  /** A literal `store()`/`driver()` name, '' for the default store. */
  store: string
  tags: string[]
  /** A literal TTL in seconds; null when absent or not readable from source. */
  ttl: number | null
}

/**
 * One outgoing request to something outside the application.
 *
 * Every field may say "unknown": `url` and `host` are empty when the address is computed at
 * runtime, `method` is empty when the source does not name one (a Guzzle `send($request)`, a
 * `curl_exec` with no verb set), and `timeout` / `retryTimes` are null when the code declares
 * neither — which is a fact about the call, not a gap in the data. `urlSource` says how much the
 * scanner could actually read, and `configKey` names the config entry when the address comes from
 * one, because `services.allegro.url` identifies the third party as well as its URL would.
 */
export interface HttpCall {
  client: 'laravel' | 'guzzle' | 'curl' | 'stream'
  method: string
  url: string
  host: string
  urlSource: 'literal' | 'constructed' | 'config' | 'env' | 'dynamic'
  configKey: string
  timeout: number | null
  retryTimes: number | null
  retrySleep: number | null
  async: boolean
}

export interface FlowStep {
  type: 'call' | 'assign' | 'return' | 'throw' | 'if' | 'loop' | 'dispatch' | 'event' | 'cache'
  label: string
  then?: FlowStep[]
  else?: FlowStep[]
  body?: FlowStep[]
  n1?: boolean
  /** Present on any step whose expression talks to the cache, whatever its `type` ended up as. */
  cache?: CacheOperation
  http?: HttpCall[]
}

export interface GraphMeta {
  project: string
  analyzedAt: string
  nodeCount: number
  edgeCount: number
}

export interface GraphNodeMetrics {
  lineCount: number
  cyclomaticComplexity: number
  statementCount: number
  paramCount: number
}

export interface GraphNode {
  id: string
  type: 'route' | 'middleware' | 'controller' | 'livewire_component' | 'action' | 'service' | 'validation_request' | 'model' | 'event' | 'listener' | 'job' | 'command' | 'channel' | 'schedule' | 'view' | 'mail' | 'notification' | 'enum' | 'interface' | 'trait' | 'abstract_class' | 'service_provider' | 'facade' | 'filament_panel' | 'filament_resource' | 'filament_page' | 'filament_page_method' | 'filament_widget' | 'filament_relation_manager' | 'ai_agent' | 'ai_tool' | 'action_class' | 'entry_point' | 'entry_point_group' | 'unreached_class' | 'unreached_group' | 'macro' | 'macro_group'
  label: string
  data: Record<string, unknown>
}

export interface GraphEdge {
  id: string
  source: string
  target: string
  label: string
  type: string
}

export interface GraphData {
  meta: GraphMeta
  nodes: GraphNode[]
  edges: GraphEdge[]
}

/**
 * What the live database reported for a model's table. Every figure is optional and they are not
 * all-or-nothing: a total size comes from every driver Laravel supports, while the row count and
 * the heap/index split need driver-specific SQL that only some can answer.
 */
export interface TableStatsData {
  rows: number | null
  tableBytes: number | null
  indexBytes: number | null
  totalBytes: number | null
  /** Row counts are the planner's estimate on most engines — cheap, and honest about it. */
  rowsEstimated: boolean
}

/** The live shape of a model's table, as the database catalogue reports it. */
export interface TableSchemaData {
  table: string
  columns: { name: string; type: string; nullable: boolean; default: string | null; autoIncrement: boolean }[]
  indexes: { name: string; columns: string[]; unique: boolean; primary: boolean }[]
  foreignKeys: {
    name: string
    columns: string[]
    foreignTable: string
    foreignColumns: string[]
    onDelete: string | null
    onUpdate: string | null
  }[]
}

/** Shape of `node.data.erd` for model nodes in the Model ERD tab. */
/** What an event promises when it broadcasts, as read from the event class itself. */
export interface BroadcastChannelData {
  name: string
  kind: 'public' | 'private' | 'presence'
  /** Every segment of the name came from a value, so which channel it is cannot be known. */
  computed: boolean
  /** A channel route in this application names the same channel. */
  declared: boolean
}

export interface BroadcastData {
  /** ShouldBroadcast goes through the queue; ShouldBroadcastNow does not. */
  queued: boolean
  /** The name subscribers listen for, when broadcastAs() renames it. */
  alias: string | null
  customPayload: boolean
  conditional: boolean
  queue: string | null
  channels: BroadcastChannelData[]
}

export interface ErdModelData {
  table: string
  primaryKey: string
  keyType: string
  incrementing: boolean
  timestamps: boolean
  softDeletes: boolean
  fillable: string[]
  guarded: string[]
  casts: Record<string, string>
  dates: string[]
  appends: string[]
  accessors: string[]
  relationships: { type: string; related: string }[]
  /** The value this model writes to `*_type` columns, when `Relation::morphMap()` names it. */
  morphAlias?: string | null
  /** The app enforces a morph map and this model is not in it — `getMorphClass()` will throw. */
  morphAliasMissing?: boolean
}

/**
 * Shape of the `data` an `unreached_class` node carries on the Reachability tab.
 *
 * `unfollowableReferences` is the load-bearing field: it is the difference between "nothing
 * reaches this from a traced entry point" and "this is dead code", and the second sentence is
 * not one Brain is in a position to make. Never render the class without it.
 */
export interface UnreachedClassData {
  kind: string
  fqcn: string
  file: string
  unfollowableReferences: string[]
  tracerBlind: boolean
  note: string
}

/** How each unfollowable-reference tag reads to someone who did not write the analyzer. */
export const UNFOLLOWABLE_REFERENCE_LABELS: Record<string, string> = {
  'container-binding': 'bound in the container',
  facade: 'reached through a facade',
  config: 'named in config/',
  'inherited-by-reached-class': 'inherited by a class that is reached',
  'class-string': 'named as a class-string elsewhere',
}

/** One node or edge in the format produced from `GraphData` (Cytoscape-compatible shape). */
export interface GraphElement {
  data: Record<string, unknown> & {
    id: string
    label?: string
    type?: string
    source?: string
    target?: string
  }
}

/** Imperative handle for zoom/pan, fit, and raster export from the D3 graph view. */
export interface GraphViewportRef {
  fit: () => void
  toPng: (options?: { scale?: number }) => Promise<string | null>
}

export interface MethodInfo {
  name: string
  flowSteps: FlowStep[]
  hasN1: boolean
}

/**
 * When and what a scheduled task runs, carried on the manifest so the sidebar row can say it
 * without the reader opening the tab.
 *
 * `cadence` is already rendered for reading — the raw expression for `cron('0 3 * * *')`,
 * `dailyAt 05:30` for a cadence that took arguments — and is empty when the schedule chain
 * never stated one at all.
 */
export interface ScheduleInfo {
  type: 'command' | 'job' | 'call'
  target: string
  cadence: string
  timezone: string
  modifiers: string[]
}

export interface TabEntry {
  id: string
  label: string
  routeCount: number
  nodeCount: number
  edgeCount: number
  file: string
  routeFile?: string
  category?: string
  panelId?: string
  issueCount?: number
  riskLevel?: 'none' | 'low' | 'medium' | 'high' | 'critical'
  securityCount?: number
  n1Count?: number
  fatMethodCount?: number
  fatClassCount?: number
  changeStatus?: 'new' | 'changed' | 'unchanged'
  schedule?: ScheduleInfo
}

/** One entry in Manifest.riskiestFiles — a file ranked by commit frequency x its most complex method. */
export interface FileRiskEntry {
  file: string
  commitCount: number
  lastChangedAt: string
  maxComplexity: number
  riskScore: number
}

/** Shape of node.data.churn when a scan found commits for that node's file within the churn window. */
export interface NodeChurnData {
  commitCount: number
  lastChangedAt: string
  lastAuthor: string
}

export interface Manifest {
  project: string
  analyzedAt: string
  previousAnalyzedAt?: string
  totalRoutes: number
  totalNodes: number
  totalEdges: number
  tabs: TabEntry[]
  riskiestFiles?: FileRiskEntry[]
}

export interface SequenceActor {
  id: string
  label: string
  type: string
  color: string
}

export interface SequenceMessage {
  fromIndex: number
  toIndex: number
  label: string
  isReturn?: boolean
  isAsync?: boolean
}

export interface SequenceDiagram {
  actors: SequenceActor[]
  messages: SequenceMessage[]
}

/** One symbol that references a node, returned by `GET /api/usages`. */
export interface NodeUsageEntry {
  nodeId: string
  label: string
  type: string
  edgeLabel: string
  edgeType: string
}

/** Usages of one node grouped by the referencing file (`file` is null when it couldn't be resolved). */
export interface NodeUsageFileGroup {
  file: string | null
  count: number
  usages: NodeUsageEntry[]
}

/** Response shape of `GET /api/usages?nodeId=...` — where a node is used across the whole project. */
export interface NodeUsages {
  nodeId: string
  label: string
  type: string
  file: string | null
  usageCount: number
  fileCount: number
  files: NodeUsageFileGroup[]
}

/** Response shape of the `history` field from `GET /api/file-history?path=...` — the last commit that touched this file. */
export interface FileHistory {
  hash: string
  shortHash: string
  /** URL to view this commit on GitHub/GitLab/Bitbucket. Null with no `origin` remote or an unparseable remote URL. */
  remoteCommitUrl: string | null
  authorName: string
  authorEmail: string
  date: string
  subject: string
  diff: string
  truncated: boolean
  /** Full file content at this commit. null only if it could not be read. */
  newContent: string | null
  newContentTruncated: boolean
  /** Full file content at the previous commit. null for the file's first commit (no previous version) or if it could not be read. */
  oldContent: string | null
  oldContentTruncated: boolean
}

export interface StressTestConfig { method: string; url: string; count: number; concurrency: number; headers: Record<string, string>; body: string; timeout: number }
export interface StressTestTiming { min: number; max: number; avg: number; p50: number; p95: number; p99: number }
export interface StressTestResult { total: number; succeeded: number; failed: number; successRate: number; errorRate: number; throughput: number; timing: StressTestTiming; statusDistribution: Record<string, number>; errors: string[]; wallTimeMs: number }

export interface EventNodeData {
  fqcn: string
  file: string
  deferred: boolean
  broadcast: boolean
  properties: string[]
  listenerCount: number
  orphan: boolean
  observableBeforeCommit: boolean
}

export interface ListenerNodeData {
  queued: boolean
  deferred: boolean
}

export interface JobNodeData {
  queued: boolean
  tries: number | null
  timeout: number | null
  backoff: number | null
  maxExceptions: number | null
  unique: boolean
  uniqueUntilProcessing: boolean
  uniqueFor: number | null
  encrypted: boolean
  afterCommit: boolean
  batchable: boolean
  middleware: string[]
  dynamic: string[]}
