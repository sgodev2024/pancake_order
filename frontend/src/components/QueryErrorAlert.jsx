import { Alert, Button } from 'antd'
import { ApiBusinessError, getApiErrorMessage, getErrorTitle } from '../utils/response.js'

const QueryErrorAlert = ({
  error,
  fallbackTitle,
  onRetry,
  className,
}) => {
  if (!error) return null

  return (
    <Alert
      className={className}
      type={error instanceof ApiBusinessError ? 'warning' : 'error'}
      showIcon
      message={getErrorTitle(error, fallbackTitle)}
      description={getApiErrorMessage(error)}
      action={onRetry && (
        <Button size="small" onClick={onRetry}>Thử lại</Button>
      )}
    />
  )
}

export default QueryErrorAlert
