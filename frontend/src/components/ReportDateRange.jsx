import { DatePicker } from "antd";
import viVN from "antd/es/date-picker/locale/vi_VN";
import dayjs from "dayjs";

const { RangePicker } = DatePicker;

const today = () => dayjs();
const startOfWeek = () => {
  const current = today();
  return current.subtract((current.day() + 6) % 7, "day").startOf("day");
};

export const reportDatePresets = [
  {
    label: "Hôm nay",
    value: () => [today().startOf("day"), today().endOf("day")],
  },
  {
    label: "Hôm qua",
    value: () => [
      today().subtract(1, "day").startOf("day"),
      today().subtract(1, "day").endOf("day"),
    ],
  },
  { label: "Tuần này", value: () => [startOfWeek(), today().endOf("day")] },
  {
    label: "Tháng này",
    value: () => [today().startOf("month"), today().endOf("day")],
  },
  {
    label: "Tháng trước",
    value: () => [
      today().subtract(1, "month").startOf("month"),
      today().subtract(1, "month").endOf("month"),
    ],
  },
  {
    label: "Năm trước",
    value: () => [today().startOf("year"), today().endOf("day")],
  },
];

const ReportDateRange = ({ value, onChange }) => (
  <RangePicker
    value={value}
    presets={reportDatePresets}
    locale={viVN}
    onChange={onChange}
    format="DD/MM/YYYY"
    placeholder={["Từ ngày", "Đến ngày"]}
    allowClear
  />
);

export default ReportDateRange;
