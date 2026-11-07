import { useCallback, useMemo, useRef, useState } from 'react'
import type { GraphData } from '../types/graph'
import type { GraphElement } from '../types/graph'

/**
 * One state per load, tagged with the file it belongs to. While the next tab loads there is no
 * data at all, so nothing can show the previous tab's graph under the new tab's name.
 */
export type TabGraphState =
  | { status: 'idle' }
  | { status: 'loading'; file: string }
  | { status: 'ready'; file: string; data: GraphData }
  | { status: 'error'; file: string; error: string }

interface UseTabGraphResult {
  state: TabGraphState
  elements: GraphElement[]
  load: (file: string) => void
}

function toElements(data: GraphData): GraphElement[] {
  return [
    ...data.nodes.map((n) => ({
      data: {
        id: n.id,
        label: n.label,
        type: n.type,
        ...n.data,
        metrics_cc: (n.data?.metrics as { cyclomaticComplexity?: number } | undefined)?.cyclomaticComplexity ?? 0,
      },
    })),
    ...data.edges.map((e) => ({
      data: { id: e.id, source: e.source, target: e.target, label: e.label, type: e.type },
    })),
  ]
}

export function useTabGraph(): UseTabGraphResult {
  const [state, setState] = useState<TabGraphState>({ status: 'idle' })
  // Cache loaded tabs so switching back is instant
  const cache = useRef<Map<string, GraphData>>(new Map())
  const currentFile = useRef<string | null>(null)
  // Only the latest request may change the state. An older fetch, even for the same file, still
  // fills the cache, but its result or error never replaces what a newer request shows.
  const latestRequest = useRef(0)

  const load = useCallback((file: string) => {
    if (currentFile.current === file) return
    currentFile.current = file
    const request = ++latestRequest.current

    // Return cached immediately
    const cached = cache.current.get(file)
    if (cached) {
      setState({ status: 'ready', file, data: cached })
      return
    }

    setState({ status: 'loading', file })

    fetch(import.meta.env.BASE_URL + file)
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`)
        return r.json()
      })
      .then((data: GraphData) => {
        cache.current.set(file, data)
        if (latestRequest.current === request) {
          setState({ status: 'ready', file, data })
        }
      })
      .catch((e: Error) => {
        if (latestRequest.current === request) {
          // Forget the file, so asking for it again fetches it again instead of keeping the error.
          currentFile.current = null
          setState({ status: 'error', file, error: e.message })
        }
      })
  }, [])

  const readyData = state.status === 'ready' ? state.data : null
  const elements = useMemo(() => (readyData ? toElements(readyData) : []), [readyData])

  return { state, elements, load }
}
