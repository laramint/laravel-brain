import { BASE } from '../lib/base'
import { useEffect, useState } from 'react'
import type { Manifest } from '../types/graph'

interface UseManifestResult {
  manifest: Manifest | null
  loading: boolean
  error: string | null
}

export function useManifest(): UseManifestResult {
  const [manifest, setManifest] = useState<Manifest | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetch(BASE + '.graph-manifest.json')
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`)
        return r.json()
      })
      .then((data: Manifest) => {
        setManifest(data)
        setLoading(false)
      })
      .catch((e: Error) => {
        setError(e.message)
        setLoading(false)
      })
  }, [])

  return { manifest, loading, error }
}
