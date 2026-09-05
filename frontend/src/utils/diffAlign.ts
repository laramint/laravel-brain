import { diffLines } from 'diff'

export type DiffRowKind = 'unchanged' | 'add' | 'remove' | 'empty'

export interface DiffRowSide {
  kind: DiffRowKind
  text: string
  lineNumber: number | null
}

export interface DiffRow {
  left: DiffRowSide
  right: DiffRowSide
}

/**
 * Splits a diff chunk's value into its lines, dropping the trailing empty string a final
 * newline in the chunk otherwise produces (e.g. "a\nb\n".split('\n') -> ['a','b','']).
 */
function linesOf(value: string): string[] {
  const lines = value.split('\n')
  if (lines.length > 0 && lines[lines.length - 1] === '') lines.pop()

  return lines
}

/**
 * Aligns two full file contents into side-by-side rows the way PhpStorm/GitHub's split diff
 * does: a run of removed lines immediately followed by a run of added lines is paired up
 * line-by-line (padding the shorter run with an empty row on the other side), rather than
 * dumping all removals above all additions. Unchanged lines appear identically on both sides
 * at the same row.
 */
export function buildSideBySideRows(oldContent: string, newContent: string): DiffRow[] {
  const changes = diffLines(oldContent, newContent)
  const rows: DiffRow[] = []

  let oldLine = 1
  let newLine = 1
  let pendingRemoved: string[] = []
  let pendingAdded: string[] = []

  const flushPending = () => {
    const count = Math.max(pendingRemoved.length, pendingAdded.length)
    for (let i = 0; i < count; i++) {
      const removedText = pendingRemoved[i]
      const addedText = pendingAdded[i]

      rows.push({
        left:
          removedText !== undefined
            ? { kind: 'remove', text: removedText, lineNumber: oldLine++ }
            : { kind: 'empty', text: '', lineNumber: null },
        right:
          addedText !== undefined
            ? { kind: 'add', text: addedText, lineNumber: newLine++ }
            : { kind: 'empty', text: '', lineNumber: null },
      })
    }
    pendingRemoved = []
    pendingAdded = []
  }

  for (const change of changes) {
    const lines = linesOf(change.value)

    if (change.removed) {
      pendingRemoved.push(...lines)
    } else if (change.added) {
      pendingAdded.push(...lines)
    } else {
      flushPending()
      for (const line of lines) {
        rows.push({
          left: { kind: 'unchanged', text: line, lineNumber: oldLine++ },
          right: { kind: 'unchanged', text: line, lineNumber: newLine++ },
        })
      }
    }
  }
  flushPending()

  return rows
}
