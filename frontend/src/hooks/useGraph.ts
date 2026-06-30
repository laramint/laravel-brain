import { BASE } from '../lib/base'
import { useEffect, useState } from 'react'
import type { GraphData, GraphNode } from '../types/graph'
import type { GraphElement } from '../types/graph'

interface UseGraphResult {
  elements: GraphElement[]
  graphData: GraphData | null
  loading: boolean
  error: string | null
}

export function useGraph(): UseGraphResult {
  const [graphData, setGraphData] = useState<GraphData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetch(BASE + 'graph.json')
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`)
        return r.json()
      })
      .then((data: GraphData) => {
        setGraphData(data)
        setLoading(false)
      })
      .catch((e: Error) => {
        setError(e.message)
        setLoading(false)
      })
  }, [])

  const elements: GraphElement[] = graphData
    ? [
        ...graphData.nodes.map((n) => ({
          data: { id: n.id, label: n.label, type: n.type, ...n.data },
        })),
        ...graphData.edges.map((e) => ({
          data: {
            id: e.id,
            source: e.source,
            target: e.target,
            label: e.label,
            type: e.type,
          },
        })),
      ]
    : []

  return { elements, graphData, loading, error }
}

export function getNodesByType(graphData: GraphData | null, type: GraphNode['type']): GraphNode[] {
  if (!graphData) return []
  return graphData.nodes.filter((n) => n.type === type)
}
