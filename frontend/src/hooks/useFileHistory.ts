import { useState, useEffect } from 'react'
import type { FileHistory } from '../types/graph'

export function useFileHistory(filePath: string | null) {
  const [data, setData] = useState<FileHistory | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Adjust state during render when filePath changes
  const [prevFilePath, setPrevFilePath] = useState(filePath)
  if (filePath !== prevFilePath) {
    setPrevFilePath(filePath)
    if (!filePath) {
      setData(null)
      setError(null)
    } else {
      setLoading(true)
      setError(null)
      setData(null)
    }
  }

  useEffect(() => {
    if (!filePath) return

    fetch(`${import.meta.env.BASE_URL}api/file-history?path=${encodeURIComponent(filePath)}`)
      .then((r) => r.json())
      .then((data) => {
        if (data.error) throw new Error(data.error)
        setData(data.history)
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }, [filePath])

  return { data, loading, error }
}
