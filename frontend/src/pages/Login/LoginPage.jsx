import { App, Button, Form, Input } from 'antd'
import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { getMe, login } from '../../api/auth.api.js'
import { useAuthStore } from '../../auth/auth.store.js'

import { ROUTES } from '../../constants/routes.js'
import { getApiErrorMessage } from '../../utils/response.js'

const getPostLoginRoute = (user, intendedRoute) => {
  if (intendedRoute) return intendedRoute

  return ROUTES.HOME
}

const LoginPage = () => {
  const [submitting, setSubmitting] = useState(false)
  const { message } = App.useApp()
  const navigate = useNavigate()
  const location = useLocation()
  const setSession = useAuthStore((state) => state.setSession)
  const clearSession = useAuthStore((state) => state.clearSession)

  const handleSubmit = async (values) => {
    if (submitting) return
    setSubmitting(true)

    try {
      const result = await login(values)
      if (!result.access_token) throw new Error('Phản hồi đăng nhập không có access token.')

      const requirePasswordChange = result.require_password_change === true
      setSession({
        token: result.access_token,
        user: result.user,
        requirePasswordChange,
      })

      if (requirePasswordChange) {
        message.info(result.message || 'Vui lòng cập nhật mật khẩu để tiếp tục.')
        navigate(ROUTES.CHANGE_FIRST_PASSWORD, { replace: true })
        return
      }

      const user = await getMe()
      setSession({ token: result.access_token, user, requirePasswordChange: false })
      message.success(result.message || 'Đăng nhập thành công')
      navigate(getPostLoginRoute(user, location.state?.from), { replace: true })
    } catch (error) {
      clearSession()
      message.error(getApiErrorMessage(error, 'Đăng nhập không thành công.'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="auth-form" aria-labelledby="login-title">
      <h1 id="login-title">Đăng nhập</h1>
      <p className="auth-form__subtitle">
        Nhập thông tin tài khoản của bạn để đăng nhập vào hệ thống
      </p>

      <Form layout="vertical" requiredMark onFinish={handleSubmit} autoComplete="on">
        <Form.Item
          label="Email"
          name="email"
          rules={[
            { required: true, message: 'Vui lòng nhập email.' },
            { type: 'email', message: 'Email chưa đúng định dạng.' },
          ]}
        >
          <Input size="large" placeholder="info@gmail.com" autoComplete="email" />
        </Form.Item>

        <Form.Item
          label="Mật khẩu"
          name="password"
          rules={[{ required: true, message: 'Vui lòng nhập mật khẩu.' }]}
        >
          <Input.Password
            size="large"
            placeholder="Nhập mật khẩu"
            autoComplete="current-password"
          />
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
          Đăng nhập
        </Button>
      </Form>
    </section>
  )
}

export default LoginPage

