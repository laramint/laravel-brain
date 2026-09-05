import { useEffect, useRef } from 'react'
import { Light as SyntaxHighlighter } from 'react-syntax-highlighter'
import php from 'react-syntax-highlighter/dist/esm/languages/hljs/php'
import atomOneDark from 'react-syntax-highlighter/dist/esm/styles/hljs/atom-one-dark'
import atomOneLight from 'react-syntax-highlighter/dist/esm/styles/hljs/atom-one-light'
import { useFileSource } from '../hooks/useFileSource'
import { FileHistoryView } from './FileHistoryView'

SyntaxHighlighter.registerLanguage('php', php)

interface Props {
  filePath: string
  highlightLine?: number
  theme: 'dark' | 'light'
}

export function SourceView({ filePath, highlightLine, theme }: Props) {
  const { content, loading, error } = useFileSource(filePath)
  const highlightRef = useRef<HTMLElement>(null)

  useEffect(() => {
    if (highlightRef.current) {
      highlightRef.current.scrollIntoView({ block: 'center', behavior: 'smooth' })
    }
  }, [content])

  const shortPath = filePath.replace(/.*\/(app|src)\//, '$1/')

  if (loading) {
    return (
      <div className="source-state">
        <div className="loading-spinner" style={{ width: 20, height: 20, borderWidth: 2 }} />
        <span>Loading source…</span>
      </div>
    )
  }

  if (error) {
    return (
      <div className="source-state source-state--error">
        Could not load file
        <small style={{ display: 'block', opacity: 0.6, marginTop: 4 }}>{error}</small>
      </div>
    )
  }

  if (!content) return null

  const style = theme === 'dark' ? atomOneDark : atomOneLight

  return (
    <div className="source-view">
      <div className="source-path" title={filePath}>{shortPath}</div>
      <FileHistoryView filePath={filePath} theme={theme} />
      <SyntaxHighlighter
        language="php"
        style={style}
        showLineNumbers
        wrapLines
        lineNumberStyle={{ minWidth: '2.5em', paddingRight: '1em', userSelect: 'none', opacity: 0.4, fontSize: 11 }}
        lineProps={(lineNumber) => {
          const isHighlight = lineNumber === highlightLine
          return isHighlight
            ? { ref: highlightRef, style: { display: 'block', backgroundColor: theme === 'dark' ? 'rgba(139,111,232,0.2)' : 'rgba(139,111,232,0.12)', borderLeft: '3px solid #8B6FE8' } }
            : { style: { display: 'block' } }
        }}
        customStyle={{
          margin: 0,
          padding: '12px 0',
          background: 'transparent',
          fontSize: 12,
          lineHeight: '1.6',
          fontFamily: 'ui-monospace, "Cascadia Code", monospace',
        }}
      >
        {content}
      </SyntaxHighlighter>
    </div>
  )
}
