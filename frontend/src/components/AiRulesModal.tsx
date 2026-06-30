import { BASE } from '../lib/base'
import { useState, useCallback } from 'react'

interface Target {
  id: string
  label: string
  path: string
  icon: string
  description: string
}

const TARGETS: Target[] = [
  { id: 'claude',   label: 'Claude Code',      path: 'CLAUDE.md',                        icon: '🟠', description: 'Anthropic Claude Code CLI & IDE' },
  { id: 'cursor',   label: 'Cursor',            path: '.cursor/rules/laravel-brain.mdc',  icon: '⬛', description: 'Cursor AI editor (MDC format with frontmatter)' },
  { id: 'windsurf', label: 'Windsurf',          path: '.windsurf/rules/laravel-brain.md', icon: '🌊', description: 'Windsurf by Codeium' },
  { id: 'copilot',  label: 'GitHub Copilot',    path: '.github/copilot-instructions.md',  icon: '🐙', description: 'Applied repo-wide automatically' },
  { id: 'junie',    label: 'JetBrains Junie',   path: '.junie/guidelines.md',             icon: '🧠', description: 'JetBrains AI assistant' },
  { id: 'aider',    label: 'Aider',             path: 'CONVENTIONS.md',                   icon: '⌨️', description: 'Load with: aider --read CONVENTIONS.md' },
  { id: 'agents',   label: 'AGENTS.md',         path: 'AGENTS.md',                        icon: '🌐', description: 'Universal open standard — 60+ tools' },
  { id: 'codex',    label: 'OpenAI Codex',      path: 'CODEX.md',                         icon: '🟢', description: 'Load with: codex --context CODEX.md' },
]

type Status = 'idle' | 'generating' | 'success' | 'error'

interface TargetStatus {
  status: Status
  path?: string
  error?: string
}

interface ExistingFile {
  target: string
  label: string
  path: string
}

interface Props {
  onClose: () => void
}

export function AiRulesModal({ onClose }: Props) {
  const [selected, setSelected] = useState<Set<string>>(new Set(TARGETS.map(t => t.id)))
  const [statuses, setStatuses] = useState<Record<string, TargetStatus>>({})
  const [generating, setGenerating] = useState(false)
  const [existingFiles, setExistingFiles] = useState<ExistingFile[] | null>(null)

  const toggleTarget = useCallback((id: string) => {
    setSelected(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }, [])

  const selectAll  = useCallback(() => setSelected(new Set(TARGETS.map(t => t.id))), [])
  const selectNone = useCallback(() => setSelected(new Set()), [])

  const doGenerate = useCallback(async (force: boolean) => {
    setGenerating(true)
    setExistingFiles(null)

    const initial: Record<string, TargetStatus> = {}
    selected.forEach(id => { initial[id] = { status: 'generating' } })
    setStatuses(initial)

    try {
      const res = await fetch(BASE + 'api/generate-rules', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ targets: [...selected], force }),
      })

      const data = await res.json()

      if (res.status === 409 && data.existing) {
        // Some files already exist — ask user to confirm overwrite
        setExistingFiles(data.existing)
        const reset: Record<string, TargetStatus> = {}
        selected.forEach(id => { reset[id] = { status: 'idle' } })
        setStatuses(reset)
        return
      }

      if (!res.ok) {
        const errMsg = data.error ?? 'Generation failed'
        const failed: Record<string, TargetStatus> = {}
        selected.forEach(id => { failed[id] = { status: 'error', error: errMsg } })
        setStatuses(failed)
        return
      }

      const next: Record<string, TargetStatus> = {}
      for (const result of (data.results ?? [])) {
        next[result.target] = result.success
          ? { status: 'success', path: result.path }
          : { status: 'error', error: result.error ?? 'Unknown error' }
      }
      setStatuses(next)
    } catch {
      const failed: Record<string, TargetStatus> = {}
      selected.forEach(id => { failed[id] = { status: 'error', error: 'Network error' } })
      setStatuses(failed)
    } finally {
      setGenerating(false)
    }
  }, [selected])

  const handleGenerate = useCallback(() => doGenerate(false), [doGenerate])
  const handleOverwrite = useCallback(() => doGenerate(true), [doGenerate])
  const handleCancelOverwrite = useCallback(() => setExistingFiles(null), [])

  const doneCount    = Object.values(statuses).filter(s => s.status === 'success').length
  const errorCount   = Object.values(statuses).filter(s => s.status === 'error').length
  const hasResults   = doneCount + errorCount > 0

  return (
    <div className="export-overlay" onClick={e => { if (e.target === e.currentTarget) onClose() }}>
      <div className="export-modal ai-rules-modal">

        {/* Header */}
        <div className="export-modal-header">
          <div className="export-modal-title">
            <span className="export-modal-icon">🤖</span>
            <div>
              <h2>Generate AI Rules Files</h2>
              <div className="export-modal-sub">Write context files for AI coding assistants into your project</div>
            </div>
          </div>
          <button className="export-modal-close" onClick={onClose}>×</button>
        </div>

        {/* Overwrite confirmation banner */}
        {existingFiles && (
          <div className="ai-rules-overwrite-banner">
            <div className="ai-rules-overwrite-icon">⚠️</div>
            <div className="ai-rules-overwrite-body">
              <strong>The following file{existingFiles.length !== 1 ? 's' : ''} already exist{existingFiles.length === 1 ? 's' : ''}:</strong>
              <ul className="ai-rules-overwrite-list">
                {existingFiles.map(f => (
                  <li key={f.target}><code>{f.path}</code></li>
                ))}
              </ul>
              <span>Do you want to overwrite {existingFiles.length !== 1 ? 'them' : 'it'}?</span>
            </div>
            <div className="ai-rules-overwrite-actions">
              <button className="export-btn export-btn--secondary" onClick={handleCancelOverwrite}>Cancel</button>
              <button className="export-btn export-btn--danger" onClick={handleOverwrite}>Overwrite</button>
            </div>
          </div>
        )}

        {/* Selection controls */}
        <div className="ai-rules-select-bar">
          <span className="ai-rules-select-label">{selected.size} of {TARGETS.length} selected</span>
          <button className="ai-rules-select-link" onClick={selectAll}>All</button>
          <span className="ai-rules-select-sep">·</span>
          <button className="ai-rules-select-link" onClick={selectNone}>None</button>
        </div>

        {/* Target cards */}
        <div className="ai-rules-grid">
          {TARGETS.map(target => {
            const isSelected = selected.has(target.id)
            const st = statuses[target.id]

            return (
              <label
                key={target.id}
                className={`ai-rules-card ${isSelected ? 'ai-rules-card--selected' : ''} ${generating ? 'ai-rules-card--disabled' : ''}`}
              >
                <input
                  type="checkbox"
                  className="ai-rules-checkbox"
                  checked={isSelected}
                  disabled={generating}
                  onChange={() => toggleTarget(target.id)}
                />
                <span className="ai-rules-card-icon">{target.icon}</span>
                <div className="ai-rules-card-body">
                  <span className="ai-rules-card-label">{target.label}</span>
                  <code className="ai-rules-card-path">{target.path}</code>
                  <span className="ai-rules-card-desc">{target.description}</span>
                </div>
                <div className="ai-rules-card-status">
                  {st?.status === 'generating' && <span className="ai-rules-status ai-rules-status--spinning">⏳</span>}
                  {st?.status === 'success'    && <span className="ai-rules-status ai-rules-status--ok" title={st.path}>✓</span>}
                  {st?.status === 'error'      && <span className="ai-rules-status ai-rules-status--err" title={st.error}>✗</span>}
                </div>
              </label>
            )
          })}
        </div>

        {/* Result summary */}
        {hasResults && (
          <div className="ai-rules-summary">
            {doneCount > 0 && <span className="ai-rules-summary--ok">✓ {doneCount} file{doneCount !== 1 ? 's' : ''} written</span>}
            {errorCount > 0 && <span className="ai-rules-summary--err">✗ {errorCount} error{errorCount !== 1 ? 's' : ''}</span>}
          </div>
        )}

        {/* Footer actions */}
        <div className="ai-rules-footer">
          <button
            className="export-btn export-btn--secondary"
            onClick={onClose}
            disabled={generating}
          >
            {hasResults ? 'Close' : 'Cancel'}
          </button>
          <button
            className={`export-btn export-btn--primary ${generating ? 'export-btn--loading' : ''}`}
            onClick={handleGenerate}
            disabled={generating || selected.size === 0}
          >
            {generating
              ? <><span className="btn-spinner btn-spinner--small" /> Generating…</>
              : `Generate ${selected.size > 0 ? selected.size : ''} File${selected.size !== 1 ? 's' : ''}`
            }
          </button>
        </div>
      </div>
    </div>
  )
}
