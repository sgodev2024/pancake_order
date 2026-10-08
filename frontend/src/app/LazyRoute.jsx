import { Suspense } from 'react'
import LoadingScreen from '../components/LoadingScreen.jsx'

const LazyRoute = ({ Page, pageProps }) => (
  <Suspense fallback={<LoadingScreen />}>
    <Page {...pageProps} />
  </Suspense>
)

export default LazyRoute
