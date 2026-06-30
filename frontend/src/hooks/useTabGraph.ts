import { BASE } from '../lib/base'
import { useCallback, useMemo, useRef, useState } from 'react'
import type { GraphData } from '../types/graph'
import type { GraphElement } from '../types/graph'

interface TabGraphState {
  data: GraphData | null
  loading: boolean
  error: string | null
}

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
  const [state, setState] = useState<TabGraphState>({ data: null, loading: false, error: null })
  // Cache loaded tabs so switching back is instant
  const cache = useRef<Map<string, GraphData>>(new Map())
  const currentFile = useRef<string | null>(null)

  const load = useCallback((file: string) => {
    if (currentFile.current === file) return
    currentFile.current = file

    // Return cached immediately
    const cached = cache.current.get(file)
    if (cached) {
      setState({ data: cached, loading: false, error: null })
      return
    }

    setState((prev) => ({ ...prev, loading: true, error: null }))

    fetch(BASE + file)
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`)
        return r.json()
      })
      .then((data: GraphData) => {
        cache.current.set(file, data)
        // Only apply if this file is still the active one
        if (currentFile.current === file) {
          setState({ data, loading: false, error: null })
        }
      })
      .catch((e: Error) => {
        if (currentFile.current === file) {
          setState({ data: null, loading: false, error: e.message })
        }
      })
  }, [])

  const elements = useMemo(() => (state.data ? toElements(state.data) : []), [state.data])

  return { state, elements, load }
}
