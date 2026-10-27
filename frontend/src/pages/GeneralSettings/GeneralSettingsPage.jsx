import { BankOutlined, DeleteOutlined, MailOutlined, PhoneOutlined, PictureOutlined, SaveOutlined } from '@ant-design/icons'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Col, Form, Input, Row, Space, Spin, Upload } from 'antd'
import { useEffect, useState } from 'react'
import { getSetting, saveSetting } from '../../api/settings.api.js'
import { getApiErrorMessage } from '../../utils/response.js'
import './general-settings.css'

const SETTING_CODE = 'general'
const SMTP_SETTING_CODE = 'smtp'
const DEFAULT_GENERAL_VALUES = {
  app_name: '',
  company_name: '',
  hotline: '',
  email: '',
  address: '',
}
const DEFAULT_SMTP_VALUES = {
  smtp_driver: 'smtp',
  smtp_host: '',
  smtp_port: '',
  smtp_from_email: '',
  smtp_from_name: '',
  smtp_encryption: '',
  smtp_username: '',
  smtp_password: '',
}

const GeneralSettingsPage = () => {
  const [generalForm] = Form.useForm()
  const [smtpForm] = Form.useForm()
  const { message } = App.useApp()
  const queryClient = useQueryClient()
  const [logoFile, setLogoFile] = useState(null)
  const [logoPreview, setLogoPreview] = useState(null)
  const [logoRemoved, setLogoRemoved] = useState(false)

  const settingQuery = useQuery({
    queryKey: ['settings', SETTING_CODE],
    queryFn: () => getSetting(SETTING_CODE),
    staleTime: 60 * 1000,
    retry: 1,
  })
  const smtpQuery = useQuery({
    queryKey: ['settings', SMTP_SETTING_CODE],
    queryFn: () => getSetting(SMTP_SETTING_CODE),
    staleTime: 60 * 1000,
    retry: 1,
  })

  useEffect(() => {
    if (settingQuery.data) {
      generalForm.setFieldsValue({
        ...DEFAULT_GENERAL_VALUES,
        ...(settingQuery.data.setting || {}),
      })
    }
    if (smtpQuery.data) {
      const smtp = smtpQuery.data.setting || {}
      smtpForm.setFieldsValue({
        ...DEFAULT_SMTP_VALUES,
        smtp_driver: smtp.driver ?? DEFAULT_SMTP_VALUES.smtp_driver,
        smtp_host: smtp.host ?? '',
        smtp_port: smtp.port ?? '',
        smtp_from_email: smtp.from_email ?? '',
        smtp_from_name: smtp.from_name ?? '',
        smtp_encryption: smtp.encryption ?? '',
        smtp_username: smtp.username ?? '',
        smtp_password: smtp.password ?? '',
      })
    }
  }, [generalForm, smtpForm, settingQuery.data, smtpQuery.data])

  const saveGeneralMutation = useMutation({
    mutationFn: (values) =>
      saveSetting({
        code: SETTING_CODE,
        data: values,
        image: logoFile,
        removeImage: logoRemoved && !logoFile,
      }),
    onSuccess: (response, values) => {
      const savedValues = response.data?.setting || values
      const savedSetting = {
        setting: savedValues,
        image: response.data?.image || settingQuery.data?.image || null,
      }
      generalForm.setFieldsValue({
        ...DEFAULT_GENERAL_VALUES,
        ...savedValues,
      })
      queryClient.setQueryData(['settings', SETTING_CODE], savedSetting)
      message.success('Đã lưu cài đặt chung.')
      setLogoFile(null)
      setLogoPreview(null)
      setLogoRemoved(false)
    },
  })
  const saveSmtpMutation = useMutation({
    mutationFn: (values) =>
      saveSetting({
        code: SMTP_SETTING_CODE,
        data: {
          driver: values.smtp_driver,
          host: values.smtp_host,
          port: values.smtp_port,
          from_email: values.smtp_from_email,
          from_name: values.smtp_from_name,
          encryption: values.smtp_encryption,
          username: values.smtp_username,
          password: values.smtp_password,
        },
      }),
    onSuccess: () => {
      message.success('Đã lưu cài đặt SMTP.')
      smtpQuery.refetch()
    },
  })

  const handleLogoChange = (info) => {
    const file = info.file
    if (!file) return

    const selectedFile = file.originFileObj || file
    setLogoFile(selectedFile)
    setLogoRemoved(false)
    const reader = new FileReader()
    reader.onload = (event) => setLogoPreview(event.target?.result)
    reader.readAsDataURL(selectedFile)
  }

  const handleGeneralSubmit = (values) => {
    if (logoRemoved && !logoFile) {
      message.warning('Cần phải cập nhật logo mới trước khi lưu.')
      return
    }
    saveGeneralMutation.mutate(values)
  }

  const displayedLogo = logoPreview || (!logoRemoved ? settingQuery.data?.image : null)
  const isLoading = settingQuery.isLoading || smtpQuery.isLoading

  return (
    <main className="general-settings-page">
      <Card
        className="general-settings-card"
        title="Cài đặt chung"
        extra={<span className="general-settings-card__hint">Thông tin hiển thị trên hệ thống</span>}
      >
        {isLoading ? (
          <div className="general-settings-loading"><Spin tip="Đang tải cài đặt..." /></div>
        ) : (
          <div className="general-settings-content">
            <Form
              form={generalForm}
              layout="vertical"
              requiredMark={false}
              initialValues={DEFAULT_GENERAL_VALUES}
              onFinish={handleGeneralSubmit}
            >
              {(settingQuery.error || saveGeneralMutation.error) && (
                <Alert
                  className="general-settings-alert"
                  type="error"
                  showIcon
                  message={settingQuery.error ? 'Không thể tải cài đặt chung' : 'Lưu cài đặt chung không thành công'}
                  description={getApiErrorMessage(settingQuery.error || saveGeneralMutation.error)}
                  closable
                />
              )}
              <section className="general-settings-section">
                <Row gutter={[28, 8]}>
                  <Col xs={24} lg={5}>
                    <div className="general-settings-logo">
                      <Upload
                        accept="image/jpeg,image/png,image/svg+xml,image/webp"
                        maxCount={1}
                        beforeUpload={() => false}
                        showUploadList={false}
                        onChange={handleLogoChange}
                      >
                        <div className="general-settings-logo__dropzone">
                          <PictureOutlined className="general-settings-logo__icon" />
                          <strong>Kéo thả logo vào đây hoặc nhấn để chọn file</strong>
                          <span>Hỗ trợ PNG, JPG, SVG. Nên dùng ảnh nền trong suốt để hiển thị đẹp hơn.</span>
                        </div>
                      </Upload>
                      {displayedLogo && (
                        <div className="general-settings-logo__file">
                          <img src={displayedLogo} alt="Logo hệ thống" />
                          <span title={logoFile?.name || 'Logo hiện tại'}>{logoFile?.name || 'Logo hiện tại'}</span>
                          <Button
                            type="text"
                            danger
                            icon={<DeleteOutlined />}
                            aria-label="Xóa logo"
                            onClick={() => {
                              setLogoFile(null)
                              setLogoPreview(null)
                              setLogoRemoved(true)
                              message.warning('Cần phải cập nhật logo mới trước khi lưu.')
                            }}
                          />
                        </div>
                      )}
                    </div>
                  </Col>
                  <Col xs={24} lg={19}>
                    <Form.Item name="app_name" hidden>
                      <Input />
                    </Form.Item>
                    <Row gutter={16}>
                      <Col xs={24}>
                        <Form.Item
                          name="company_name"
                          label="Tên công ty"
                          rules={[{ required: true, message: 'Vui lòng nhập tên công ty.' }]}
                        >
                          <Input prefix={<BankOutlined />} placeholder="Nhập tên công ty" />
                        </Form.Item>
                      </Col>
                    </Row>
                    <Row gutter={16}>
                      <Col xs={24} md={12}>
                        <Form.Item
                          name="hotline"
                          label="SĐT"
                          rules={[{ required: true, message: 'Vui lòng nhập số điện thoại.' }]}
                        >
                          <Input prefix={<PhoneOutlined />} placeholder="Nhập số điện thoại" />
                        </Form.Item>
                      </Col>
                      <Col xs={24} md={12}>
                        <Form.Item
                          name="email"
                          label="Email"
                          rules={[
                            { required: true, message: 'Vui lòng nhập email.' },
                            { type: 'email', message: 'Email chưa đúng định dạng.' },
                          ]}
                        >
                          <Input prefix={<MailOutlined />} placeholder="contact@example.com" />
                        </Form.Item>
                      </Col>
                    </Row>
                    <Form.Item
                      name="address"
                      label="Địa chỉ"
                      rules={[{ required: true, message: 'Vui lòng nhập địa chỉ.' }]}
                    >
                      <Input.TextArea rows={4} placeholder="Nhập địa chỉ công ty" />
                    </Form.Item>
                  </Col>
                </Row>
              </section>
              <div className="general-settings-actions">
                <Space>
                  <Button onClick={() => generalForm.resetFields()} disabled={saveGeneralMutation.isPending}>Đặt lại</Button>
                  <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={saveGeneralMutation.isPending}>
                    Lưu cài đặt
                  </Button>
                </Space>
              </div>
            </Form>

            <Form
              form={smtpForm}
              layout="vertical"
              requiredMark={false}
              initialValues={DEFAULT_SMTP_VALUES}
              onFinish={(values) => saveSmtpMutation.mutate(values)}
            >
              {(smtpQuery.error || saveSmtpMutation.error) && (
                <Alert
                  className="general-settings-alert"
                  type="error"
                  showIcon
                  message={smtpQuery.error ? 'Không thể tải cài đặt SMTP' : 'Lưu cài đặt SMTP không thành công'}
                  description={getApiErrorMessage(smtpQuery.error || saveSmtpMutation.error)}
                  closable
                />
              )}
              <section className="general-settings-section general-settings-smtp">
                <h2>Cài đặt SMTP</h2>
                <p>Cấu hình máy chủ email dùng để gửi thông báo từ hệ thống.</p>
                <Row gutter={16}>
                  <Col xs={24} md={8}>
                    <Form.Item name="smtp_driver" label="Driver" rules={[{ required: true, message: 'Vui lòng nhập driver.' }]}>
                      <Input placeholder="smtp" />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={8}>
                    <Form.Item name="smtp_host" label="Host" rules={[{ required: true, message: 'Vui lòng nhập host.' }]}>
                      <Input placeholder="smtp.example.com" />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={8}>
                    <Form.Item name="smtp_port" label="Port" rules={[{ required: true, message: 'Vui lòng nhập port.' }]}>
                      <Input placeholder="587" />
                    </Form.Item>
                  </Col>
                </Row>
                <Row gutter={16}>
                  <Col xs={24} md={8}>
                    <Form.Item
                      name="smtp_from_email"
                      label="From Email"
                      rules={[
                        { required: true, message: 'Vui lòng nhập email gửi.' },
                        { type: 'email', message: 'Email chưa đúng định dạng.' },
                      ]}
                    >
                      <Input placeholder="no-reply@example.com" />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={8}>
                    <Form.Item name="smtp_from_name" label="From Name" rules={[{ required: true, message: 'Vui lòng nhập tên người gửi.' }]}>
                      <Input placeholder="Pancake Order" />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={8}>
                    <Form.Item name="smtp_encryption" label="Encryption" rules={[{ required: true, message: 'Vui lòng nhập encryption.' }]}>
                      <Input placeholder="tls" />
                    </Form.Item>
                  </Col>
                </Row>
                <Row gutter={16}>
                  <Col xs={24} md={8}>
                    <Form.Item name="smtp_username" label="Username" rules={[{ required: true, message: 'Vui lòng nhập username.' }]}>
                      <Input placeholder="Username..." />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={8}>
                    <Form.Item name="smtp_password" label="Password" rules={[{ required: true, message: 'Vui lòng nhập password.' }]}>
                      <Input.Password placeholder="Password..." />
                    </Form.Item>
                  </Col>
                </Row>
              </section>
              <div className="general-settings-actions">
                <Space>
                  <Button onClick={() => smtpForm.resetFields()} disabled={saveSmtpMutation.isPending}>Đặt lại</Button>
                  <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={saveSmtpMutation.isPending}>
                    Lưu cài đặt 
                  </Button>
                </Space>
              </div>
            </Form>
          </div>
        )}
      </Card>
    </main>
  )
}

export default GeneralSettingsPage
