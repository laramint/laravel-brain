import { useState, useRef } from 'react'
import type { FlowStep } from '../types/graph'
import { ExportModal } from './ExportModal'
import { flowStepsToMermaid, domToPng, downloadPng } from '../utils/exportUtils'
import '../flowchart.css'

interface Props {
  steps: FlowStep[]
  title?: string
  isFatMethod?: boolean
}

export function FlowchartView({ steps, title, isFatMethod }: Props) {
  const [showMermaid, setShowMermaid] = useState(false)
  const [exporting, setExporting] = useState(false)
  const flowRef = useRef<HTMLDivElement>(null)

  if (!steps || steps.length === 0) {
    return (
      <div className="flowchart-empty">
        <span>No flow data available</span>
      </div>
    )
  }

  const methodLabel = title ?? 'method'

  const handleExportPng = async () => {
    if (!flowRef.current) return
    setExporting(true)
    try {
      const dataUrl = await domToPng(flowRef.current)
      downloadPng(dataUrl, `${methodLabel.replace(/[^a-z0-9]/gi, '_')}_flow.png`)
    } finally {
      setExporting(false)
    }
  }

  const handleExportMermaid = () => setShowMermaid(true)

  return (
    <>
      {isFatMethod && (
        <div className="flowchart-fat-banner" title="Fat Method: this method exceeds complexity or line-count thresholds">
          🧱 Fat Method — consider breaking this into smaller methods
        </div>
      )}
      <div className="flowchart-export-bar">
        <button
          className="flowchart-export-btn"
          onClick={handleExportPng}
          disabled={exporting}
          title="Export as PNG"
        >
          {exporting ? '⏳' : '🖼'} PNG
        </button>
        <button
          className="flowchart-export-btn"
          onClick={handleExportMermaid}
          title="Export as Mermaid"
        >
          🗺 Mermaid
        </button>
      </div>

      <div className="flowchart-root" ref={flowRef}>
        {title && <div className="flowchart-title">{title}</div>}
        <FlowStepList steps={steps} />
      </div>

      {showMermaid && (
        <ExportModal
          mermaidCode={flowStepsToMermaid(steps, methodLabel)}
          filename={`${methodLabel.replace(/[^a-z0-9]/gi, '_')}_flow.mmd`}
          title={methodLabel}
          onClose={() => setShowMermaid(false)}
        />
      )}
    </>
  )
}

function FlowStepList({ steps }: { steps: FlowStep[] }) {
  return (
    <div className="flowchart-list">
      {steps.map((step, i) => (
        <FlowStepNode key={i} step={step} isLast={i === steps.length - 1} />
      ))}
    </div>
  )
}

function FlowStepNode({ step, isLast }: { step: FlowStep; isLast: boolean }) {
  if (step.type === 'if') {
    return (
      <div className="flowchart-branch-wrapper">
        <FlowBox step={step} />
        {/* Arrow down into branches */}
        <div className="flowchart-branches">
          {step.then && step.then.length > 0 && (
            <div className="flowchart-branch flowchart-branch--then">
              <div className="flowchart-branch-label">then</div>
              <FlowStepList steps={step.then} />
            </div>
          )}
          {step.else && step.else.length > 0 && (
            <div className="flowchart-branch flowchart-branch--else">
              <div className="flowchart-branch-label">else</div>
              <FlowStepList steps={step.else} />
            </div>
          )}
        </div>
        {!isLast && <FlowArrow />}
      </div>
    )
  }

  if (step.type === 'loop') {
    return (
      <div className="flowchart-branch-wrapper">
        <FlowBox step={step} />
        {step.body && step.body.length > 0 && (
          <div className="flowchart-loop-body">
            <FlowStepList steps={step.body} />
          </div>
        )}
        {!isLast && <FlowArrow />}
      </div>
    )
  }

  return (
    <>
      <FlowBox step={step} />
      {!isLast && <FlowArrow />}
    </>
  )
}

function FlowBox({ step }: { step: FlowStep }) {
  const cls = `flowchart-box flowchart-box--${step.type} ${step.n1 ? 'flowchart-box--n1' : ''}`
  const icon = STEP_ICONS[step.type] ?? ''
  const shape = step.type === 'if' ? 'diamond' : step.type === 'return' || step.type === 'throw' ? 'terminal' : 'rect'

  return (
    <div className={`${cls} flowchart-shape--${shape}`} title={step.label}>
      {icon && <span className="flowchart-icon">{icon}</span>}
      <span className="flowchart-label">{step.label}</span>
      {/*
        A cache badge rides on the step whatever its type ended up as. `Cache::remember(…, fn)`
        is re-typed to `loop` so the chart can descend into the closure, and a returned cache
        read stays a `return`; in both, the colour alone would not say the cache was involved.
      */}
      {step.cache && (
        <span
          className={`flowchart-cache-badge flowchart-cache-badge--${step.cache.kind}`}
          title={cacheBadgeTitle(step)}
        >
          {step.cache.kind}
        </span>
      )}
      {step.n1 && (
        <span className="flowchart-n1-warn" title="N+1 Query Detected: This database operation is inside a loop!">
          ⚠️ N+1
        </span>
      )}
      {step.http && step.http.length > 0 && (
        <span
          className="flowchart-http"
          title={step.http
            .map(c => `${c.method || 'REQUEST'} ${c.host || (c.configKey && `config('${c.configKey}')`) || 'address computed at runtime'}` +
              (c.timeout === null ? ' · no timeout' : ` · timeout ${c.timeout}s`))
            .join('\n')}
        >
          🌐 {step.http.map(c => c.host).find(Boolean) ?? 'external'}
        </span>
      )}
    </div>
  )
}

/** The full detail of a cache step, for the hover the badge itself has no room for. */
function cacheBadgeTitle(step: FlowStep): string {
  const op = step.cache
  if (!op) return ''

  const key = op.keyKind === 'computed' ? 'computed key'
    : op.keyKind === 'none' ? 'whole store'
    : `"${op.key}"`
  const extras = [
    op.ttl !== null ? `ttl ${op.ttl}s` : '',
    op.store !== '' ? `store ${op.store}` : '',
    op.tags.length > 0 ? `tags ${op.tags.join(', ')}` : '',
  ].filter(Boolean)

  return `${op.kind} · ${op.method} ${key}${extras.length > 0 ? ` · ${extras.join(' · ')}` : ''}`
}

function FlowArrow() {
  return (
    <div className="flowchart-arrow">
      <div className="flowchart-arrow-line" />
      <div className="flowchart-arrow-head" />
    </div>
  )
}

const STEP_ICONS: Record<string, string> = {
  call:     '→',
  assign:   '=',
  return:   '◀',
  throw:    '⚠',
  if:       '◆',
  loop:     '↻',
  dispatch: '⚡',
  event:    '📡',
  cache:    '⛃',
}
