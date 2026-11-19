import { App, Button, Form, Input } from 'antd'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { changeFirstPassword } from '../../api/auth.api.js'
import { isSessionSuperseded, useAuthStore } from '../../auth/auth.store.js'
import { ROUTES } from '../../constants/routes.js'
import { getApiErrorMessage } from '../../utils/response.js'

const ChangeFirstPasswordPage = () => {
  const [submitting, setSubmitting] = useState(false)
  const { message } = App.useApp()
  const navigate = useNavigate()
  const completeSession = useAuthStore((state) => state.completeSession)
  const clearSession = useAuthStore((state) => state.clearSession)
  const beginSessionAttempt = useAuthStore((state) => state.beginSessionAttempt)
  const isCurrentSessionAttempt = useAuthStore((state) => state.isCurrentSessionAttempt)

  const handleSubmit = async (values) => {
    if (submitting) return
    setSubmitting(true)
    const attempt = beginSessionAttempt()

    try {
      const result = await changeFirstPassword({
        current_password: values.current_password,
        new_password: values.new_password,
        new_password_confirmation: values.new_password_confirmation,
      })

      if (!result.access_token) throw new Error('Phản hồi đổi mật khẩu không có access token mới.')

      await completeSession({ ...result, require_password_change: false }, { attempt })
      message.success(result.message || 'Đổi mật khẩu thành công.')
      navigate(ROUTES.HOME, { replace: true })
    } catch (error) {
      if (isSessionSuperseded(error) || !isCurrentSessionAttempt(attempt)) return
      message.error(getApiErrorMessage(error, 'Không thể đổi mật khẩu.'))

      if (error?.status === 401 || error?.response?.status === 401) {
        clearSession()
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="auth-form auth-form--password" aria-labelledby="password-title">
      <h1 id="password-title">Cập nhật mật khẩu</h1>
      <p className="auth-form__subtitle">
        Đây là lần đăng nhập đầu tiên. Vui lòng tạo mật khẩu mới để tiếp tục.
      </p>

      <Form layout="vertical" requiredMark onFinish={handleSubmit} autoComplete="off">
        <Form.Item
          label="Mật khẩu hiện tại"
          name="current_password"
          rules={[{ required: true, message: 'Vui lòng nhập mật khẩu hiện tại.' }]}
        >
          <Input.Password size="large" placeholder="Nhập mật khẩu hiện tại" />
        </Form.Item>

        <Form.Item
          label="Mật khẩu mới"
          name="new_password"
          rules={[
            { required: true, message: 'Vui lòng nhập mật khẩu mới.' },
            { min: 6, message: 'Mật khẩu mới cần ít nhất 6 ký tự.' },
          ]}
        >
          <Input.Password size="large" placeholder="Tối thiểu 6 ký tự" />
        </Form.Item>

        <Form.Item
          label="Xác nhận mật khẩu mới"
          name="new_password_confirmation"
          dependencies={['new_password']}
          rules={[
            { required: true, message: 'Vui lòng xác nhận mật khẩu mới.' },
            ({ getFieldValue }) => ({
              validator(_, value) {
                if (!value || getFieldValue('new_password') === value) return Promise.resolve()
                return Promise.reject(new Error('Mật khẩu xác nhận không khớp.'))
              },
            }),
          ]}
        >
          <Input.Password size="large" placeholder="Nhập lại mật khẩu mới" />
        </Form.Item>

        <Button
          type="primary"
          htmlType="submit"
          size="large"
          block
          loading={submitting}
          disabled={submitting}
          className="auth-submit"
        >
          Cập nhật mật khẩu
        </Button>
      </Form>
    </section>
  )
}

export default ChangeFirstPasswordPage
