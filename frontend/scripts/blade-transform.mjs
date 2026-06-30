import { readFileSync, writeFileSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'

const __dir = dirname(fileURLToPath(import.meta.url))
const bladePath = resolve(__dir, '../../resources/views/index.blade.php')

let html = readFileSync(bladePath, 'utf8')

// Replace hardcoded /_laravel-brain/ with a Blade variable
html = html.replace(/\/_laravel-brain\//g, '{{ $brainPrefix }}/')

// Inject the @php block and runtime window.__BRAIN_BASE__ after <head>
const phpBlock = `    @php $brainPrefix = '/'.config('laravel-brain.route_prefix', '_laravel-brain'); @endphp\n`
const runtimeScript = `    <script>window.__BRAIN_BASE__ = @json($brainPrefix . '/');</script>\n`

html = html.replace('<head>\n', `<head>\n${phpBlock}`)
html = html.replace(/<link rel="icon"/, `${runtimeScript}    <link rel="icon"`)

writeFileSync(bladePath, html)
console.log('Blade template written to', bladePath)
