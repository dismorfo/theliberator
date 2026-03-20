import Mirador, { getCanvasIndex, getCanvases, getWindowIds, setCanvas } from 'mirador';
import { miradorImageToolsPlugin } from 'mirador-image-tools';
import { defaultConfig } from './viewerConfig.js'
import './style.css'

const uuid = 'mirador-app'

const elem = document.getElementById(uuid)

const {
  identifier,
  language,
  sequence,
  manifest,
  searchQuery,
} = elem.dataset

const config = {
  ...defaultConfig,
  ...{
    id: uuid,
    language,
    windows: [
      {
        manifestId: manifest,
        imageToolsEnabled: true,
        imageToolsOpen: false,
        canvasIndex: Number(sequence) - 1,
        defaultSearchQuery: searchQuery || undefined,
        view: 'single',
        defaultSidebarPanel: 'search',
        sideBarOpenByDefault: true,
        hideWindowTitle: true,
      },
    ],
    window: {
      allowWindowSideBar: true,
      sideBarOpenByDefault: true,
      panels: {
        search: true,
        info: true,
        attribution: true,
        canvas: true,
      }
    },
  },
}

const mirador = Mirador.viewer(config, [
  ...miradorImageToolsPlugin,
])

const historyWindow = window.parent !== window ? window.parent : window
let currentPage = null
let suppressHistoryUpdate = false

const buildBookUrl = (page) => {
  const targetUrl = new URL(historyWindow.location.href)

  targetUrl.pathname = `/book/${identifier}/${page}`

  return targetUrl
}

const syncBookPageUrl = () => {
  try {
    const state = mirador.store.getState()
    const windowIds = getWindowIds(state)
    const windowId = windowIds[0]

    if (!windowId) return

    const page = getCanvasIndex(state, { windowId }) + 1

    if (!Number.isInteger(page) || page < 1 || page === currentPage) return

    const targetUrl = buildBookUrl(page)

    if (suppressHistoryUpdate) {
      historyWindow.history.replaceState(historyWindow.history.state, '', targetUrl)
      suppressHistoryUpdate = false
    } else if (currentPage === null) {
      historyWindow.history.replaceState(historyWindow.history.state, '', targetUrl)
    } else {
      historyWindow.history.pushState(historyWindow.history.state, '', targetUrl)
    }

    currentPage = page
  } catch (error) {
    console.warn('Failed to sync book page URL.', error)
  }
}

const syncMiradorPageFromUrl = () => {
  try {
    const match = historyWindow.location.pathname.match(/\/book\/([^/]+)\/(\d+)$/)

    if (!match || match[1] !== identifier) return

    const requestedPage = Number(match[2])

    if (!Number.isInteger(requestedPage) || requestedPage < 1) return

    const state = mirador.store.getState()
    const windowIds = getWindowIds(state)
    const windowId = windowIds[0]

    if (!windowId) return

    const currentPage = getCanvasIndex(state, { windowId }) + 1

    if (requestedPage === currentPage) return

    const canvases = getCanvases(state, { windowId })
    const targetCanvas = canvases[requestedPage - 1]

    if (!targetCanvas?.id) return

    suppressHistoryUpdate = true
    mirador.store.dispatch(setCanvas(windowId, targetCanvas.id))
  } catch (error) {
    console.warn('Failed to sync Mirador page from URL.', error)
  }
}

syncBookPageUrl()
mirador.store.subscribe(syncBookPageUrl)
historyWindow.addEventListener('popstate', syncMiradorPageFromUrl)
