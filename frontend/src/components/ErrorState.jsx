import { Alert } from 'antd'

const ErrorState = ({ title = 'Không thể tải nội dung', message }) => (
  <div className="error-state">
    <Alert type="error" showIcon message={title} description={message} />
  </div>
)

export default ErrorState
