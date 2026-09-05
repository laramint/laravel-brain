import { useState } from 'react'
import type { FileRiskEntry } from '../types/graph'
import { ccTier } from '../utils/graphConstants'
import { SourceModal } from './SourceModal'
import { Tooltip } from './Tooltip'

interface Props {
  files: FileRiskEntry[] | undefined
  theme: 'dark' | 'light'
}

/**
 * Files ranked by recent commit frequency x their own most complex method — the "code as a
 * crime scene" signal for where the next bug is likeliest. Deliberately does not try to jump
 * to a specific node/tab (this codebase has no cross-tab node-navigation mechanism); clicking
 * a row opens the file's source directly via the existing SourceModal, which brings the
 * git-history byline and diff link along for free.
 */
export function RiskiestFilesPanel({ files, theme }: Props) {
  // Collapsed by default and height-capped when open (below) — this sits above the route
  // tree, which is the sidebar's primary content. Defaulting to open, with no cap, buried the
  // tree under up to `churn.limit` (50 by default) rows on first paint. Never do that again:
  // the sidebar's default appearance must be unchanged unless a person opts into this.
  const [open, setOpen] = useState(false)
  const [openFile, setOpenFile] = useState<string | null>(null)

  if (!files || files.length === 0) return null

  return (
    <div className="sidebar-section">
      <div
        className="sidebar-section-header"
        onClick={() => setOpen((v) => !v)}
        style={{ cursor: 'pointer' }}
      >
        <h3>
          <span className="tree-group-chevron">{open ? '▾' : '▸'}</span> Riskiest Files
        </h3>
      </div>

      {open && (
        <div className="complexity-panel riskiest-files-panel">
          <Tooltip content="Complexity alone says a method is hard to read; commit frequency alone says a file is popular. Together they say where the next bug is likeliest. riskScore = most complex method's cyclomatic complexity x recent commit count.">
            <div className="complexity-summary">
              {files.length} file{files.length === 1 ? '' : 's'}
            </div>
          </Tooltip>

          <div className="complexity-list riskiest-files-list">
            {files.map((f) => {
              const tier = ccTier(f.maxComplexity, theme === 'dark')
              const shortPath = f.file.replace(/.*\/(app|src)\//, '$1/')

              return (
                <Tooltip
                  key={f.file}
                  content={`Complexity ${f.maxComplexity} x ${f.commitCount} commit${f.commitCount === 1 ? '' : 's'} (last changed ${f.lastChangedAt}). Click to view source.`}
                >
                  <button type="button" className="complexity-row" onClick={() => setOpenFile(f.file)}>
                    <span className="complexity-badge" style={{ color: tier.border, borderColor: tier.border }}>
                      {f.riskScore}
                    </span>
                    <span className="complexity-label">{shortPath}</span>
                    <span className="complexity-type">
                      {f.commitCount} commit{f.commitCount === 1 ? '' : 's'}
                    </span>
                  </button>
                </Tooltip>
              )
            })}
          </div>
        </div>
      )}

      {openFile && <SourceModal filePath={openFile} theme={theme} onClose={() => setOpenFile(null)} />}
    </div>
  )
}
