import { useRef, useState, useCallback, useMemo } from 'react'
import { FilterPanel } from './FilterPanel'
import { Tooltip } from './Tooltip'
import { SECURITY_RISK_COLORS, SECURITY_SEVERITY_LABELS } from '../utils/graphConstants'
import type { TabEntry, GraphData } from '../types/graph'

const RISK_ORDER: Record<string, number> = { none: 0, low: 1, medium: 2, high: 3, critical: 4 }

const MIN_WIDTH = 280
const MAX_WIDTH = 480
const DEFAULT_WIDTH = 300

interface TreeNode {
  name: string
  path: string
  isCategory: boolean
  children: TreeNode[]
  leaves: TabEntry[]
}

interface Props {
  tabs: TabEntry[]
  activeId: string | null
  loadingId: string | null
  onSelect: (tab: TabEntry) => void
  mode: 'routes' | 'risks' | 'recent'
  onModeChange: (m: 'routes' | 'risks' | 'recent') => void
  previousAnalyzedAt?: string
  visibleTypes: Set<string>
  counts: Record<string, number>
  onToggle: (type: string) => void
  onShowAll: () => void
  onHideAll: () => void
  graphData: GraphData | null
  complexityFilter: 'all' | 'complex' | 'critical'
  onComplexityFilterChange: (f: 'all' | 'complex' | 'critical') => void
  onNodeSelect: (id: string) => void
  selectedId: string | null
}

const METHOD_COLORS: Record<string, string> = {
  GET: '#4ade80',
  POST: '#60a5fa',
  PUT: '#f59e0b',
  PATCH: '#a78bfa',
  DELETE: '#f87171',
}

const ALL_HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const

function splitLabel(label: string): { method: string | null; uri: string } {
  const [first, ...rest] = label.split(' ')
  if (first in METHOD_COLORS) return { method: first, uri: rest.join(' ') }
  return { method: null, uri: label }
}

function riskOf(tab: TabEntry): string {
  return tab.riskLevel ?? 'none'
}

function riskDescription(tab: TabEntry): string {
  const parts: string[] = []
  if (tab.securityCount) parts.push(`${tab.securityCount} security`)
  if (tab.n1Count) parts.push(`${tab.n1Count} N+1`)
  const fat = (tab.fatMethodCount ?? 0) + (tab.fatClassCount ?? 0)
  if (fat) parts.push(`${fat} fat`)
  return parts.length ? parts.join(' · ') : 'flagged for review'
}

function relativeTime(from?: string): string {
  if (!from) return 'new'
  const ms = Date.now() - new Date(from).getTime()
  const mins = Math.floor(ms / 60000)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

function RouteItem({ tab, isActive, isLoading, onSelect }: {
  tab: TabEntry
  isActive: boolean
  isLoading: boolean
  onSelect: (tab: TabEntry) => void
}) {
  const { method, uri } = splitLabel(tab.label)
  const color = method ? METHOD_COLORS[method] : 'var(--faint)'
  const risk = riskOf(tab)
  const badgeColor = risk === 'high' || risk === 'critical'
    ? 'var(--danger)'
    : tab.issueCount
      ? 'var(--warn)'
      : null

  return (
    <Tooltip content={`Open lifecycle graph · ${tab.nodeCount} nodes · ${tab.edgeCount} edges`}>
      <button
        className={`route-row ${isActive ? 'route-row--active' : ''}`}
        type="button"
        onClick={() => onSelect(tab)}
      >
        <span className="route-row-method" style={{ color }}>{method ?? '›'}</span>
        <span className="route-row-uri">{uri}</span>
        {badgeColor && (
          <span className="route-row-risk" style={{ '--rc': badgeColor } as React.CSSProperties}>
            {tab.issueCount}
          </span>
        )}
        {isLoading && <span className="route-row-loading">…</span>}
      </button>
    </Tooltip>
  )
}

// Modern monochrome (lucide-style) icon glyphs. Each entry is the inner SVG
// for a shared 24×24 stroke wrapper, so every icon renders in the same grey.
type IconKey =
  | 'shield' | 'lock' | 'key' | 'user' | 'users' | 'building' | 'dashboard'
  | 'settings' | 'card' | 'cart' | 'package' | 'file' | 'message' | 'bell'
  | 'mail' | 'search' | 'folder' | 'download' | 'upload' | 'chart' | 'list'
  | 'activity' | 'link' | 'zap' | 'box' | 'calendar' | 'pin' | 'book' | 'info'
  | 'beaker' | 'tag' | 'broadcast' | 'hash' | 'terminal' | 'clock' | 'route'

const ICON_PATHS: Record<IconKey, React.ReactNode> = {
  shield: <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />,
  lock: <><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></>,
  key: <><circle cx="7.5" cy="15.5" r="4.5" /><path d="m10.7 12.3 8.3-8.3" /><path d="m17 5 3 3" /><path d="m15 7 3 3" /></>,
  user: <><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></>,
  users: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></>,
  building: <><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M9 22v-4h6v4" /><path d="M8 6h.01M16 6h.01M8 10h.01M16 10h.01M8 14h.01M16 14h.01" /></>,
  dashboard: <><rect x="3" y="3" width="7" height="9" /><rect x="14" y="3" width="7" height="5" /><rect x="14" y="12" width="7" height="9" /><rect x="3" y="16" width="7" height="5" /></>,
  settings: <><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" /><circle cx="12" cy="12" r="3" /></>,
  card: <><rect x="2" y="5" width="20" height="14" rx="2" /><line x1="2" y1="10" x2="22" y2="10" /></>,
  cart: <><circle cx="9" cy="21" r="1" /><circle cx="20" cy="21" r="1" /><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" /></>,
  package: <><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" /><path d="M3.27 6.96 12 12.01l8.73-5.05" /><path d="M12 22.08V12" /></>,
  file: <><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /></>,
  message: <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />,
  bell: <><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" /></>,
  mail: <><rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-10 5L2 7" /></>,
  search: <><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></>,
  folder: <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />,
  download: <><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></>,
  upload: <><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></>,
  chart: <><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></>,
  list: <><line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" /></>,
  activity: <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />,
  link: <><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" /></>,
  zap: <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />,
  box: <><rect x="4" y="4" width="16" height="16" rx="2" /><rect x="9" y="9" width="6" height="6" /></>,
  calendar: <><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /></>,
  pin: <><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" /><circle cx="12" cy="10" r="3" /></>,
  book: <><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></>,
  info: <><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></>,
  beaker: <><path d="M9 3h6" /><path d="M10 3v6l-5.5 9.5A2 2 0 0 0 6.2 21h11.6a2 2 0 0 0 1.7-3.5L14 9V3" /></>,
  tag: <><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" /><line x1="7" y1="7" x2="7.01" y2="7" /></>,
  broadcast: <><path d="M4 11a9 9 0 0 1 9 9" /><path d="M4 4a16 16 0 0 1 16 16" /><circle cx="5" cy="19" r="1" /></>,
  hash: <><line x1="4" y1="9" x2="20" y2="9" /><line x1="4" y1="15" x2="20" y2="15" /><line x1="10" y1="3" x2="8" y2="21" /><line x1="16" y1="3" x2="14" y2="21" /></>,
  terminal: <><polyline points="4 17 10 11 4 5" /><line x1="12" y1="19" x2="20" y2="19" /></>,
  clock: <><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></>,
  route: <><circle cx="6" cy="19" r="3" /><circle cx="18" cy="5" r="3" /><path d="M9 19h6a4 4 0 0 0 4-4V9" /></>,
}

function GroupIcon({ name }: { name: IconKey }) {
  return (
    <svg
      className="tree-group-icon"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {ICON_PATHS[name]}
    </svg>
  )
}

// Keyword → icon rules for first-level route groups. First match wins,
// so order most-specific before generic. Tested against the path segment name.
const ROUTE_ICON_RULES: [RegExp, IconKey][] = [
  [/^(auth|login|register|signin|signup|signout|logout|verify)/i, 'lock'],
  [/^(password|forgot|reset|recover)/i, 'key'],
  [/^(oauth|sso|saml|token|jwt|sanctum|passport)/i, 'key'],
  [/^(admin|backend|manage|mgmt|cp|role|permission|acl|guard|policy|gate|abilit|security|firewall|protect|shield)/i, 'shield'],
  [/^(team|organization|org|company|tenant|workspace)/i, 'building'],
  [/^(user|account|profile|member|people|person)/i, 'user'],
  [/^(group|staff|contributor|follower)/i, 'users'],
  [/^(dashboard|home|overview|index|main|panel)/i, 'dashboard'],
  [/^(setting|config|preference|option|env)/i, 'settings'],
  [/^(billing|payment|invoice|subscription|plan|pricing|wallet|transaction|refund)/i, 'card'],
  [/^(checkout|cart|basket|bag)/i, 'cart'],
  [/^(order|purchase|fulfil|shipping|delivery|product|catalog|catalogue|item|shop|store|inventory|stock)/i, 'package'],
  [/^(blog|post|article|news|content|page|cms)/i, 'file'],
  [/^(message|chat|conversation|inbox|thread|dm|comment|review|rating|feedback|reply)/i, 'message'],
  [/^(notification|notif|alert|push)/i, 'bell'],
  [/^(mail|email|newsletter|campaign)/i, 'mail'],
  [/^(search|explore|discover|find|query|filter)/i, 'search'],
  [/^(upload|file|files|media|image|photo|asset|document|docs?|attachment|storage)/i, 'folder'],
  [/^(download|export|backup|dump)/i, 'download'],
  [/^(import|sync|migrate)/i, 'upload'],
  [/^(report|analytic|stat|statistic|metric|insight|chart|kpi)/i, 'chart'],
  [/^(log|logs|audit|activity|history|track|trace)/i, 'list'],
  [/^(health|status|ping|up|ready|live|heartbeat|probe|monitor)/i, 'activity'],
  [/^(webhook|callback|hook|integration|connect|link)/i, 'link'],
  [/^(cache|redis|optimize)/i, 'zap'],
  [/^(queue|job|jobs|worker|batch|cron)/i, 'box'],
  [/^(calendar|event|booking|appointment|reservation|slot)/i, 'calendar'],
  [/^(map|location|geo|address|place|region|country)/i, 'pin'],
  [/^(project|board|workflow|pipeline)/i, 'folder'],
  [/^(help|support|faq|guide|tutorial|kb|knowledge|wiki)/i, 'book'],
  [/^(contact|enquir|inquir|lead)/i, 'user'],
  [/^(about|info|legal|privacy|terms|policy)/i, 'info'],
  [/^(test|tests|debug|dev|sandbox|playground|demo|example)/i, 'beaker'],
  [/^(tag|tags|category|categories|topic|label)/i, 'tag'],
  [/^(feed|rss|atom|socket|ws|realtime|broadcast|stream)/i, 'broadcast'],
  [/^(api|graphql|ql|rest|rpc)$/i, 'hash'],
  [/^v?\d+(\.\d+)*$/i, 'hash'],
]

const CATEGORY_ICONS: Record<string, IconKey> = {
  'Console Commands': 'terminal',
  'Broadcast Channels': 'broadcast',
  Schedules: 'clock',
  'Model ERD': 'box',
  'Event Choreography': 'zap',
  'AI Agents': 'zap',
  Other: 'route',
}

function groupIconName(name: string, isCategory: boolean): IconKey {
  if (isCategory) {
    if (name.startsWith('Filament')) return 'box'
    return CATEGORY_ICONS[name] ?? 'route'
  }
  for (const [re, icon] of ROUTE_ICON_RULES) {
    if (re.test(name)) return icon
  }
  return 'route'
}

function categoryBucket(tab: TabEntry): string {
  if (tab.category === 'Command') return 'Console Commands'
  if (tab.category === 'Channel') return 'Broadcast Channels'
  if (tab.category === 'Schedule') return 'Schedules'
  if (tab.category === 'ERD') return 'Model ERD'
  if (tab.category === 'Events') return 'Event Choreography'
  if (tab.category === 'AI') return 'AI Agents'
  if (tab.category === 'Filament') {
    const p = tab.panelId ?? ''
    return p ? `Filament · ${p.charAt(0).toUpperCase()}${p.slice(1)} Panel` : 'Filament'
  }
  return 'Other'
}

function sortTree(node: TreeNode): void {
  node.children.sort((a, b) => a.name.localeCompare(b.name))
  node.leaves.sort((a, b) => a.label.localeCompare(b.label))
  node.children.forEach(sortTree)
}

function routeSegments(tab: TabEntry): string[] | null {
  const first = tab.label.split(' ')[0]
  if (!(first in METHOD_COLORS)) return null
  const uri = tab.label.slice(first.length).trim()
  return uri.split('/').filter(Boolean)
}

function buildTree(tabs: TabEntry[]): TreeNode {
  const root: TreeNode = { name: '', path: '', isCategory: false, children: [], leaves: [] }

  const childByName = (parent: TreeNode, name: string, isCategory: boolean): TreeNode => {
    let child = parent.children.find((c) => c.name === name)
    if (!child) {
      child = {
        name,
        path: parent.path ? `${parent.path}/${name}` : name,
        isCategory,
        children: [],
        leaves: [],
      }
      parent.children.push(child)
    }
    return child
  }

  const dirs = new Set<string>()
  for (const tab of tabs) {
    const segments = routeSegments(tab)
    if (!segments) continue
    const groupSegments = segments.slice(0, -1)
    for (let i = 1; i <= groupSegments.length; i++) {
      dirs.add(groupSegments.slice(0, i).join('/'))
    }
  }

  for (const tab of tabs) {
    const segments = routeSegments(tab)

    if (!segments) {
      const bucket = childByName(root, categoryBucket(tab), true)
      bucket.leaves.push(tab)
      continue
    }

    const fullPath = segments.join('/')
    const targetSegments = fullPath !== '' && dirs.has(fullPath)
      ? segments
      : segments.slice(0, -1)

    let node = root
    for (const seg of targetSegments) {
      node = childByName(node, seg, false)
    }
    node.leaves.push(tab)
  }

  sortTree(root)
  return root
}

function leafCount(node: TreeNode): number {
  return node.leaves.length + node.children.reduce((s, c) => s + leafCount(c), 0)
}

function TreeGroup({
  node, forceOpen, expanded, onToggle, activeId, loadingId, onSelect, level = 0,
}: {
  node: TreeNode
  forceOpen: boolean
  expanded: Set<string>
  onToggle: (path: string) => void
  activeId: string | null
  loadingId: string | null
  onSelect: (tab: TabEntry) => void
  level?: number
}) {
  const open = forceOpen || expanded.has(node.path)
  const label = node.isCategory ? node.name : `/${node.name}`

  return (
    <div className="tree-group">
      <button type="button" className="tree-group-header" onClick={() => onToggle(node.path)}>
        <span className="tree-group-chevron">{open ? '▾' : '▸'}</span>
        {level === 0 && <GroupIcon name={groupIconName(node.name, node.isCategory)} />}
        <span className="tree-group-name">{label}</span>
        <span className="tree-group-count">{leafCount(node)}</span>
      </button>

      {open && (
        <div className="tree-group-body">
          {node.children.map((child) => (
            <TreeGroup
              key={child.path}
              node={child}
              forceOpen={forceOpen}
              expanded={expanded}
              onToggle={onToggle}
              activeId={activeId}
              loadingId={loadingId}
              onSelect={onSelect}
              level={level + 1}
            />
          ))}
          {node.leaves.map((tab) => (
            <RouteItem
              key={tab.id}
              tab={tab}
              isActive={tab.id === activeId}
              isLoading={tab.id === loadingId}
              onSelect={onSelect}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function FlagCard({ tab, isActive, onSelect, timestamp }: {
  tab: TabEntry
  isActive: boolean
  onSelect: (tab: TabEntry) => void
  timestamp?: string
}) {
  const { method, uri } = splitLabel(tab.label)
  const risk = riskOf(tab)
  const sev = risk === 'critical' ? 'critical' : risk === 'high' ? 'high' : risk === 'medium' ? 'medium' : 'low'
  const sevColor = SECURITY_RISK_COLORS[sev] ?? SECURITY_RISK_COLORS.medium

  return (
    <button
      type="button"
      className={`flag-card ${isActive ? 'flag-card--active' : ''}`}
      onClick={() => onSelect(tab)}
    >
      <div className="flag-card-top">
        {timestamp
          ? <span className="flag-card-time">{timestamp}</span>
          : <span className="flag-card-sev" style={{ '--sc': sevColor } as React.CSSProperties}>
              {(SECURITY_SEVERITY_LABELS[sev] ?? sev).toUpperCase()}
            </span>}
        {method && (
          <span className="flag-card-method" style={{ color: METHOD_COLORS[method] }}>{method}</span>
        )}
      </div>
      <div className="flag-card-path">{uri}</div>
      <div className="flag-card-desc">{riskDescription(tab)}</div>
    </button>
  )
}

export function LeftSidebar({
  tabs, activeId, loadingId, onSelect,
  mode, onModeChange, previousAnalyzedAt,
  visibleTypes, counts, onToggle, onShowAll, onHideAll,
}: Props) {
  const [width, setWidth] = useState(DEFAULT_WIDTH)
  const [search, setSearch] = useState('')
  const [visibleMethods, setVisibleMethods] = useState<Set<string>>(new Set(ALL_HTTP_METHODS))
  const [expanded, setExpanded] = useState<Set<string>>(new Set())

  const toggleMethod = useCallback((m: string) => {
    setVisibleMethods((prev) => {
      const next = new Set(prev)
      if (next.has(m)) next.delete(m)
      else next.add(m)
      return next
    })
  }, [])

  const toggleNode = useCallback((path: string) =>
    setExpanded((s) => {
      const n = new Set(s)
      if (n.has(path)) n.delete(path)
      else n.add(path)
      return n
    }), [])

  const isDraggingWidth = useRef(false)
  const startX = useRef(0)
  const startWidth = useRef(DEFAULT_WIDTH)

  const onWidthMouseDown = useCallback((e: React.MouseEvent) => {
    e.preventDefault()
    isDraggingWidth.current = true
    startX.current = e.clientX
    startWidth.current = width

    const onMove = (ev: MouseEvent) => {
      if (!isDraggingWidth.current) return
      const delta = ev.clientX - startX.current
      setWidth(Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startWidth.current + delta)))
    }
    const onUp = () => {
      isDraggingWidth.current = false
      window.removeEventListener('mousemove', onMove)
      window.removeEventListener('mouseup', onUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }

    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
    window.addEventListener('mousemove', onMove)
    window.addEventListener('mouseup', onUp)
  }, [width])

  const query = search.trim().toLowerCase()

  // The search box and the method chips filter the sidebar, all three modes of it. Applied once
  // here so that every list below and every count beside a mode name comes from the same set —
  // they used to be derived separately, and only the route tree was filtered at all.
  const filteredTabs = useMemo(() => {
    const allMethodsVisible = ALL_HTTP_METHODS.every((m) => visibleMethods.has(m))
    return tabs.filter((t) => {
      if (query && !t.label.toLowerCase().includes(query)) return false
      if (!allMethodsVisible) {
        const firstWord = t.label.split(' ')[0]
        if (firstWord in METHOD_COLORS && !visibleMethods.has(firstWord)) return false
      }
      return true
    })
  }, [tabs, query, visibleMethods])

  const isFiltering = query.length > 0 || !ALL_HTTP_METHODS.every((m) => visibleMethods.has(m))

  const tree = useMemo(() => buildTree(filteredTabs), [filteredTabs])

  const riskTabs = useMemo(
    () => filteredTabs
      .filter((t) => riskOf(t) !== 'none')
      .sort((a, b) => (RISK_ORDER[riskOf(b)] ?? 0) - (RISK_ORDER[riskOf(a)] ?? 0)),
    [filteredTabs],
  )

  const recentTabs = useMemo(
    () => filteredTabs.filter((t) => t.changeStatus === 'new' || t.changeStatus === 'changed'),
    [filteredTabs],
  )

  // Counted from the very lists they label, rather than from separately computed props. That is
  // what made them disagree: `Risks` showed the parent's high-and-critical tally above a list of
  // everything flagged, so the two differed whenever a route was medium or low risk.
  const modeTabs: { id: 'routes' | 'risks' | 'recent'; label: string; count: number }[] = [
    { id: 'routes', label: 'Routes', count: filteredTabs.length },
    { id: 'risks', label: 'Risks', count: riskTabs.length },
    { id: 'recent', label: 'Recent', count: recentTabs.length },
  ]

  return (
    <div className="left-sidebar-resizable" style={{ width }}>
      <div className="left-sidebar">
        <div className="left-search">
          <input
            className="left-search-input"
            type="text"
            placeholder="Search routes…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {search && (
            <button type="button" className="left-search-clear" onClick={() => setSearch('')}>×</button>
          )}
        </div>

        <div className="left-method-chips">
          {ALL_HTTP_METHODS.map((m) => (
            <button
              key={m}
              type="button"
              className={`method-chip ${visibleMethods.has(m) ? 'method-chip--on' : ''}`}
              style={{ '--mc': METHOD_COLORS[m] } as React.CSSProperties}
              onClick={() => toggleMethod(m)}
            >
              {m}
            </button>
          ))}
        </div>

        <div className="mode-tabs">
          {modeTabs.map((t) => (
            <button
              key={t.id}
              type="button"
              className={`mode-tab ${mode === t.id ? 'mode-tab--active' : ''}`}
              onClick={() => onModeChange(t.id)}
            >
              {t.label}
              <span
                className={`mode-tab-count ${t.id === 'risks' && mode === 'risks' && t.count > 0 ? 'mode-tab-count--alert' : ''}`}
              >
                {t.count}
              </span>
            </button>
          ))}
        </div>

        <div className="left-content">
          {mode === 'routes' && (
            <div className="route-tree">
              {tree.children.length === 0 && tree.leaves.length === 0 && (
                <div className="left-empty">No routes match.</div>
              )}
              {tree.children.map((child) => (
                <TreeGroup
                  key={child.path}
                  node={child}
                  forceOpen={query.length > 0}
                  expanded={expanded}
                  onToggle={toggleNode}
                  activeId={activeId}
                  loadingId={loadingId}
                  onSelect={onSelect}
                />
              ))}
              {tree.leaves.map((tab) => (
                <RouteItem
                  key={tab.id}
                  tab={tab}
                  isActive={tab.id === activeId}
                  isLoading={tab.id === loadingId}
                  onSelect={onSelect}
                />
              ))}
            </div>
          )}

          {mode === 'risks' && (
            <div className="flag-list">
              {riskTabs.length === 0 && (
                <div className="left-empty">
                  {isFiltering ? 'No flagged routes match the filter.' : 'No flagged routes. ✓'}
                </div>
              )}
              {riskTabs.map((tab) => (
                <FlagCard key={tab.id} tab={tab} isActive={tab.id === activeId} onSelect={onSelect} />
              ))}
            </div>
          )}

          {mode === 'recent' && (
            <div className="flag-list">
              {recentTabs.length === 0 && (
                <div className="left-empty">
                  {isFiltering
                    ? 'Nothing matching the filter changed since the previous scan.'
                    : 'Nothing changed since the previous scan.'}
                </div>
              )}
              {recentTabs.map((tab) => (
                <FlagCard
                  key={tab.id}
                  tab={tab}
                  isActive={tab.id === activeId}
                  onSelect={onSelect}
                  timestamp={`${tab.changeStatus === 'new' ? 'new' : 'changed'} · ${relativeTime(previousAnalyzedAt)}`}
                />
              ))}
            </div>
          )}
        </div>

        <div className="left-footer">
          <FilterPanel
            visibleTypes={visibleTypes}
            counts={counts}
            onToggle={onToggle}
            onShowAll={onShowAll}
            onHideAll={onHideAll}
          />
        </div>
      </div>
      <Tooltip content="Drag to resize">
        <div className="left-sidebar-drag-handle" onMouseDown={onWidthMouseDown} />
      </Tooltip>
    </div>
  )
}
