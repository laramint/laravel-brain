import { useState } from 'react'
import { useFileHistory } from '../hooks/useFileHistory'
import { DiffModal } from './DiffModal'

interface Props {
  filePath: string
  theme: 'dark' | 'light'
}

export function FileHistoryView({ filePath, theme }: Props) {
  const { data, loading, error } = useFileHistory(filePath)
  const [showDiff, setShowDiff] = useState(false)

  if (loading) {
    return (
      <div className="file-history file-history--loading">
        <div className="loading-spinner" style={{ width: 12, height: 12, borderWidth: 2 }} />
        <span>Loading history…</span>
      </div>
    )
  }

  // Non-essential metadata alongside the actual source code — fail quietly rather than
  // crowd the view with an error banner over something the user did not ask to see.
  if (error) return null

  if (!data) {
    return <div className="file-history file-history--empty">No commit history available.</div>
  }

  const when = (() => {
    const parsed = new Date(data.date)
    return Number.isNaN(parsed.getTime()) ? data.date : parsed.toLocaleString()
  })()

  const hasDiff = data.newContent !== null || data.oldContent !== null

  return (
    <div className="file-history">
      <div className="file-history-byline">
        {data.remoteCommitUrl ? (
          <a
            href={data.remoteCommitUrl}
            target="_blank"
            rel="noreferrer"
            className="ins-chip ins-chip--neutral ins-chip--link"
            title={`${data.hash} — open on remote`}
          >
            {data.shortHash}
          </a>
        ) : (
          <span className="ins-chip ins-chip--neutral" title={data.hash}>
            {data.shortHash}
          </span>
        )}
        <span className="file-history-author" title={data.authorEmail}>
          {data.authorName}
        </span>
        <span className="file-history-date">{when}</span>
        <span className="file-history-subject" title={data.subject}>
          {data.subject}
        </span>
        {hasDiff && (
          <button type="button" className="file-history-toggle" onClick={() => setShowDiff(true)}>
            Show diff
          </button>
        )}
      </div>
      {showDiff && (
        <DiffModal
          filePath={filePath}
          subject={data.subject}
          oldContent={data.oldContent}
          newContent={data.newContent}
          theme={theme}
          onClose={() => setShowDiff(false)}
        />
      )}
    </div>
  )
}
