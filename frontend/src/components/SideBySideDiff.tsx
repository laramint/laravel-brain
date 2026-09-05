import { useCallback, useEffect, useRef } from 'react'
import { Light as SyntaxHighlighter } from 'react-syntax-highlighter'
import php from 'react-syntax-highlighter/dist/esm/languages/hljs/php'
import atomOneDark from 'react-syntax-highlighter/dist/esm/styles/hljs/atom-one-dark'
import atomOneLight from 'react-syntax-highlighter/dist/esm/styles/hljs/atom-one-light'
import { buildSideBySideRows, type DiffRow, type DiffRowKind } from '../utils/diffAlign'

// Registering here too (idempotent) rather than relying on SourceView having already run —
// this component can be reached without SourceView ever mounting.
SyntaxHighlighter.registerLanguage('php', php)

interface Props {
  oldContent: string | null
  newContent: string | null
  theme: 'dark' | 'light'
}

function backgroundFor(kind: DiffRowKind, theme: 'dark' | 'light'): string {
  switch (kind) {
    case 'add':
      return theme === 'dark' ? 'rgba(76,175,80,0.18)' : 'rgba(76,175,80,0.14)'
    case 'remove':
      return theme === 'dark' ? 'rgba(244,67,54,0.18)' : 'rgba(244,67,54,0.14)'
    case 'empty':
      return theme === 'dark' ? 'rgba(255,255,255,0.03)' : 'rgba(0,0,0,0.03)'
    default:
      return 'transparent'
  }
}

function Pane({
  rows,
  side,
  theme,
  wrapperRef,
  onScroll,
}: {
  rows: DiffRow[]
  side: 'left' | 'right'
  theme: 'dark' | 'light'
  wrapperRef: React.RefObject<HTMLDivElement | null>
  onScroll: (scrollLeft: number, scrollTop: number) => void
}) {
  const text = rows.map((r) => r[side].text).join('\n')
  const style = theme === 'dark' ? atomOneDark : atomOneLight

  // The actual scroll container — both axes — is the <pre> the highlighter renders internally
  // (it carries its own overflow-x: auto from the theme, and per spec that forces overflow-y
  // to behave as auto too, since one axis can't stay "visible" while the other isn't), not
  // this wrapper div — react-syntax-highlighter doesn't forward a ref to it, so it's found via
  // the DOM after mount. .diff-gutter has no scroll container of its own (see its CSS): it's
  // kept aligned by copying <pre>'s scrollTop here, on every scroll event <pre> fires for any
  // reason — a wheel event over the code, or the cross-pane sync below setting scrollTop
  // programmatically, both fire the same native event.
  useEffect(() => {
    const pre = wrapperRef.current?.querySelector('pre');
    const gutter = wrapperRef.current?.querySelector<HTMLDivElement>('.diff-gutter');
    if (!pre) return;

    const handleScroll = () => {
      if (gutter) gutter.scrollTop = pre.scrollTop;
      onScroll(pre.scrollLeft, pre.scrollTop);
    };
    pre.addEventListener('scroll', handleScroll);

    return () => pre.removeEventListener('scroll', handleScroll);
  }, [wrapperRef, onScroll]);

  return (
    <div className="diff-pane" ref={wrapperRef}>
      <div className="diff-gutter">
        {rows.map((r, i) => (
          <div key={i} className={`diff-gutter-line diff-gutter-line--${r[side].kind}`}>
            {r[side].lineNumber ?? ''}
          </div>
        ))}
      </div>
      <SyntaxHighlighter
        language="php"
        style={style}
        // react-syntax-highlighter only computes a real per-line number when showLineNumbers
        // is truthy (internally: `showLineNumbers && index + startingLineNumber`, so a false
        // showLineNumbers makes every line's "number" the boolean false, not 0/1/2/...,  and
        // that's what lineProps below would receive). We don't want ITS line numbers — we
        // render our own gutter — so inline numbers and the separate number column are both
        // turned off/hidden; showLineNumbers stays on purely to make lineProps usable.
        showLineNumbers
        showInlineLineNumbers={false}
        lineNumberContainerStyle={{ display: 'none' }}
        wrapLines
        lineProps={(lineNumber) => ({
          style: {
            display: 'block',
            background: backgroundFor(rows[lineNumber - 1]?.[side].kind ?? 'unchanged', theme),
          },
        })}
        // <pre> is the horizontal-scroll container (overflow-x: auto, fixed width from the
        // flex layout above). The library's default <code> is display:inline, so each line's
        // display:block background box sizes itself against <pre>'s fixed viewport width —
        // not the actual content width — meaning a line longer than that viewport overflows
        // as scrollable text with no background behind the overflowing part at all. Forcing
        // <code> to inline-block makes it shrink-to-fit its widest line instead (grows past
        // <pre>'s box when content demands it, which is exactly what a scroll container's
        // child is allowed to do), so every line's box — including short ones, via min-width
        // — spans the full scrollable width, not just the visible one. display:table would
        // size the same way but also wraps block children in anonymous table-row/cell boxes,
        // which breaks the vertical line stacking entirely — inline-block does not.
        codeTagProps={{ style: { display: 'inline-block', minWidth: '100%' } }}
        customStyle={{
          margin: 0,
          padding: 0,
          background: 'transparent',
          fontSize: 12,
          lineHeight: '1.6',
          fontFamily: 'ui-monospace, "Cascadia Code", monospace',
          flex: 1,
        }}
      >
        {text}
      </SyntaxHighlighter>
    </div>
  )
}

/** PhpStorm-style split diff: two synced-scroll, syntax-highlighted panes with a real (gapped) line-number gutter per side. */
export function SideBySideDiff({ oldContent, newContent, theme }: Props) {
  const rows = buildSideBySideRows(oldContent ?? '', newContent ?? '')
  const leftRef = useRef<HTMLDivElement>(null)
  const rightRef = useRef<HTMLDivElement>(null)
  const syncing = useRef(false)

  const syncPre = useCallback((targetRef: React.RefObject<HTMLDivElement | null>, scrollLeft: number, scrollTop: number) => {
    if (syncing.current) return
    const pre = targetRef.current?.querySelector('pre')
    if (!pre) return

    syncing.current = true
    pre.scrollLeft = scrollLeft
    pre.scrollTop = scrollTop
    syncing.current = false
  }, [])

  const handleLeftScroll = useCallback((scrollLeft: number, scrollTop: number) => {
    syncPre(rightRef, scrollLeft, scrollTop)
  }, [syncPre])

  const handleRightScroll = useCallback((scrollLeft: number, scrollTop: number) => {
    syncPre(leftRef, scrollLeft, scrollTop)
  }, [syncPre])

  return (
    <div className="diff-side-by-side">
      <Pane rows={rows} side="left" theme={theme} wrapperRef={leftRef} onScroll={handleLeftScroll} />
      <Pane rows={rows} side="right" theme={theme} wrapperRef={rightRef} onScroll={handleRightScroll} />
    </div>
  )
}
