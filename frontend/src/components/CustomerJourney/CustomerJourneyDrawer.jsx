import { useInfiniteQuery } from '@tanstack/react-query'
import { Alert, Drawer } from 'antd'
import { useMemo } from 'react'
import { getCustomerJourney } from '../../api/customers.api.js'
import CustomerJourneySummary from './CustomerJourneySummary.jsx'
import CustomerJourneyTimeline from './CustomerJourneyTimeline.jsx'
import './customer-journey.css'

const JOURNEY_PAGE_SIZE = 50

const getJourneyErrorMessage = (error) => {
  const status = error?.status ?? error?.response?.status
  if (status === 403) return 'Bạn không có quyền xem hành trình khách hàng này.'
  if (status === 404) return 'Không tìm thấy khách hàng.'
  return 'Không thể tải hành trình khách hàng. Vui lòng thử lại.'
}

const CustomerJourneyDrawer = ({ open, customer, onClose }) => {
  const customerId = customer?.id
  const hasLocalCustomerId = customerId !== null && customerId !== undefined && customerId !== ''
  const journeyQuery = useInfiniteQuery({
    queryKey: ['customer-journey', customerId],
    queryFn: ({ pageParam }) => getCustomerJourney(customerId, { page: pageParam, page_size: JOURNEY_PAGE_SIZE }),
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      const currentPage = Number(lastPage?.current_page) || 1
      const totalPages = Number(lastPage?.total_pages) || 1
      return currentPage < totalPages ? currentPage + 1 : undefined
    },
    enabled: open && hasLocalCustomerId,
    retry: false,
  })

  const events = useMemo(() => {
    const seenIds = new Set()
    return (journeyQuery.data?.pages ?? []).flatMap((page) => page?.timeline ?? []).filter((event) => {
      if (event?.id === null || event?.id === undefined || seenIds.has(event.id)) return false
      seenIds.add(event.id)
      return true
    })
  }, [journeyQuery.data])

  const summary = journeyQuery.data?.pages?.[0]?.summary

  return (
    <Drawer
      title={`Hành trình khách hàng${customer?.name ? ` — ${customer.name}` : ''}`}
      open={open}
      onClose={onClose}
      destroyOnHidden
      width="min(720px, 100vw)"
      className="customer-journey-drawer"
    >
      {journeyQuery.isError ? (
        <Alert type="error" showIcon message={getJourneyErrorMessage(journeyQuery.error)} />
      ) : (
        <div className="customer-journey">
          {summary && <CustomerJourneySummary summary={summary} />}
          <CustomerJourneyTimeline
            events={events}
            isLoading={journeyQuery.isLoading}
            hasNextPage={journeyQuery.hasNextPage}
            isFetchingNextPage={journeyQuery.isFetchingNextPage}
            onLoadMore={() => journeyQuery.fetchNextPage()}
          />
        </div>
      )}
    </Drawer>
  )
}

export default CustomerJourneyDrawer
