import { BASE } from '../lib/base'
import { useState, useEffect } from 'react'

export function useFileSource(filePath: string | null) {
  const [content, setContent] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Adjust state during render when filePath changes
  const [prevFilePath, setPrevFilePath] = useState(filePath)
  if (filePath !== prevFilePath) {
    setPrevFilePath(filePath)
    if (!filePath) {
      setContent(null)
      setError(null)
    } else {
      setLoading(true)
      setError(null)
      setContent(null)
    }
  }

  useEffect(() => {
    if (!filePath) return

    fetch(`${BASE}api/source?path=${encodeURIComponent(filePath)}`)
      .then((r) => r.json())
      .then((data) => {
        if (data.error) throw new Error(data.error)
        setContent(data.content)
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }, [filePath])

  return { content, loading, error }
}
