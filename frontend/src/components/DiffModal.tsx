import { useEffect } from 'react'
import { SideBySideDiff } from './SideBySideDiff'

interface Props {
  filePath: string
  subject: string
  oldContent: string | null
  newContent: string | null
  theme: 'dark' | 'light'
  onClose: () => void
}

export function DiffModal({ filePath, subject, oldContent, newContent, theme, onClose }: Props) {
  // Close on Escape
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const title = filePath.split('/').pop() || 'Diff'

  return (
    <div className="modal-overlay" onClick={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div className="modal-container modal-container--large">
        <div className="modal-header">
          <div className="modal-title">
            <span className="modal-icon">⇄</span>
            <div>
              <h2>{title}</h2>
              <span className="modal-sub" title={filePath}>{filePath} — {subject}</span>
            </div>
          </div>
          <button className="modal-close" onClick={onClose} title="Close (Esc)">×</button>
        </div>

        <div className="modal-body diff-modal-body">
          <SideBySideDiff oldContent={oldContent} newContent={newContent} theme={theme} />
        </div>
      </div>
    </div>
  )
}
