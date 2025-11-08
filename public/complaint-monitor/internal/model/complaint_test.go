package model

import "testing"

func TestExtractAgentIDFromOrderNo(t *testing.T) {
	tests := []struct {
		name     string
		orderNo  string
		expected int
	}{
		{
			name:     "单位数代理商ID",
			orderNo:  "BY120251022211850C4CA7731",
			expected: 1,
		},
		{
			name:     "双位数代理商ID",
			orderNo:  "BY1520251022211850C4CA7731",
			expected: 15,
		},
		{
			name:     "三位数代理商ID",
			orderNo:  "BY12820251022211850C4CA7731",
			expected: 128,
		},
		{
			name:     "空订单号",
			orderNo:  "",
			expected: 0,
		},
		{
			name:     "格式不正确的订单号",
			orderNo:  "XY120251022211850C4CA7731",
			expected: 0,
		},
		{
			name:     "订单号太短",
			orderNo:  "BY1",
			expected: 0,
		},
		{
			name:     "日期格式不正确",
			orderNo:  "BY119991022211850C4CA7731",
			expected: 0,
		},
		{
			name:     "2024年的订单",
			orderNo:  "BY520241022211850C4CA7731",
			expected: 5,
		},
		{
			name:     "2026年的订单",
			orderNo:  "BY1020260101123456",
			expected: 10,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			result := ExtractAgentIDFromOrderNo(tt.orderNo)
			if result != tt.expected {
				t.Errorf("ExtractAgentIDFromOrderNo(%s) = %d, 期望 %d", tt.orderNo, result, tt.expected)
			}
		})
	}
}

func TestComplaint_SetAgentIDFromOrderNo(t *testing.T) {
	// 测试从订单详情中提取代理商ID
	complaint := &Complaint{
		SubjectID:   4,
		ComplaintNo: "COMP123456",
		Details: []ComplaintDetail{
			{
				MerchantOrderNo: "BY120251022211850C4CA7731",
			},
		},
	}

	complaint.SetAgentIDFromOrderNo()

	if complaint.AgentID != 1 {
		t.Errorf("SetAgentIDFromOrderNo() 期望 AgentID = 1, 实际 = %d", complaint.AgentID)
	}

	// 测试不重复设置
	complaint.SetAgentIDFromOrderNo()
	if complaint.AgentID != 1 {
		t.Errorf("SetAgentIDFromOrderNo() 不应该重复设置, AgentID = %d", complaint.AgentID)
	}
}

func TestComplaint_SetAgentIDFromOrderNo_NoDetails(t *testing.T) {
	// 测试没有订单详情的情况
	complaint := &Complaint{
		SubjectID:   4,
		ComplaintNo: "COMP123456",
		Details:     []ComplaintDetail{},
	}

	complaint.SetAgentIDFromOrderNo()

	if complaint.AgentID != 0 {
		t.Errorf("SetAgentIDFromOrderNo() 期望 AgentID = 0 (无订单详情), 实际 = %d", complaint.AgentID)
	}
}

func TestComplaint_SetAgentIDFromOrderNo_AlreadySet(t *testing.T) {
	// 测试已经设置代理商ID的情况
	complaint := &Complaint{
		SubjectID:   4,
		AgentID:     99, // 已经设置
		ComplaintNo: "COMP123456",
		Details: []ComplaintDetail{
			{
				MerchantOrderNo: "BY120251022211850C4CA7731",
			},
		},
	}

	complaint.SetAgentIDFromOrderNo()

	if complaint.AgentID != 99 {
		t.Errorf("SetAgentIDFromOrderNo() 不应该覆盖已有的 AgentID, 期望 = 99, 实际 = %d", complaint.AgentID)
	}
}
