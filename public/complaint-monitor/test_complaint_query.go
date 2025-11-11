package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"time"

	"complaint-monitor/internal/cert"
	"complaint-monitor/internal/config"
	"complaint-monitor/internal/logger"
	"complaint-monitor/internal/repository"
	"complaint-monitor/internal/service"

	"go.uber.org/zap"
)

func main() {
	// 加载配置
	cfg, err := config.LoadWithDefaults("configs/config.local.yaml")
	if err != nil {
		log.Fatalf("加载配置失败: %v", err)
	}

	// 初始化日志
	loggerInstance, err := logger.NewLoggerWithOptions(cfg.App.LogLevel, cfg.IsDevelopment())
	if err != nil {
		log.Fatalf("初始化日志失败: %v", err)
	}
	defer loggerInstance.Sync()

	// 初始化数据库连接
	database, err := repository.NewDatabase(&cfg.Database, loggerInstance)
	if err != nil {
		log.Fatalf("初始化数据库失败: %v", err)
	}
	defer database.Close()

	// 查询第一个激活的主体
	subjectRepo := repository.NewSubjectRepository(database.GetDB(), loggerInstance)
	subjects, err := subjectRepo.FindActiveWithCert()
	if err != nil {
		log.Fatalf("查询主体失败: %v", err)
	}

	if len(subjects) == 0 {
		log.Fatalf("没有找到激活的主体")
	}

	subject := subjects[0]
	loggerInstance.Info("找到主体",
		zap.Int("subject_id", subject.ID),
		zap.String("app_id", subject.AlipayAppID),
	)

	// 初始化证书管理器
	certManager := cert.NewCertManager(
		[]byte(cfg.Cert.EncryptionKey),
		cfg.Cert.GetCacheTTL(),
		loggerInstance,
	)

	// 加载证书
	client, err := certManager.LoadCert(subject)
	if err != nil {
		log.Fatalf("加载证书失败: %v", err)
	}

	// 初始化AlipayService
	alipayService := service.NewAlipayService(loggerInstance)

	// 测试多个时间范围和查询条件
	testCases := []struct {
		name      string
		beginTime string
		endTime   string
	}{
		{
			name:      "不传时间参数（查询所有）",
			beginTime: "",
			endTime:   "",
		},
		{
			name:      "过去10天到明天（参考代码）",
			beginTime: time.Now().AddDate(0, 0, -10).Format("2006-01-02 15:04:05"),
			endTime:   time.Now().AddDate(0, 0, 1).Format("2006-01-02 15:04:05"),
		},
		{
			name:      "过去30天到明天",
			beginTime: time.Now().AddDate(0, 0, -30).Format("2006-01-02 15:04:05"),
			endTime:   time.Now().AddDate(0, 0, 1).Format("2006-01-02 15:04:05"),
		},
		{
			name:      "过去90天到明天",
			beginTime: time.Now().AddDate(0, 0, -90).Format("2006-01-02 15:04:05"),
			endTime:   time.Now().AddDate(0, 0, 1).Format("2006-01-02 15:04:05"),
		},
		{
			name:      "过去1年到明天",
			beginTime: time.Now().AddDate(-1, 0, 0).Format("2006-01-02 15:04:05"),
			endTime:   time.Now().AddDate(0, 0, 1).Format("2006-01-02 15:04:05"),
		},
		{
			name:      "过去2年到明天",
			beginTime: time.Now().AddDate(-2, 0, 0).Format("2006-01-02 15:04:05"),
			endTime:   time.Now().AddDate(0, 0, 1).Format("2006-01-02 15:04:05"),
		},
	}

	for i, testCase := range testCases {
		fmt.Printf("\n=== 测试用例 %d: %s ===\n", i+1, testCase.name)
		fmt.Printf("Subject ID: %d\n", subject.ID)
		fmt.Printf("App ID: %s\n", subject.AlipayAppID)
		if testCase.beginTime != "" {
			fmt.Printf("Begin Time: %s\n", testCase.beginTime)
			fmt.Printf("End Time: %s\n", testCase.endTime)
		} else {
			fmt.Printf("Time Range: 不设置（查询所有数据）\n")
		}
		fmt.Printf("\n")

		// 构建请求
		listReq := service.ComplaintListRequest{
			BeginTime: testCase.beginTime,
			EndTime:   testCase.endTime,
			PageNum:   1,
			PageSize:  200,
		}

		// 调用投诉列表API
		fmt.Printf("开始调用投诉列表API...\n")
		listResp, err := alipayService.FetchComplaintList(client, listReq)
		if err != nil {
			fmt.Printf("❌ 调用失败: %v\n", err)
			fmt.Printf("继续测试下一个用例...\n\n")
			continue
		}

		// 打印结果
		fmt.Printf("✅ 调用成功\n")
		fmt.Printf("Total: %d\n", listResp.Total)
		fmt.Printf("Count: %d\n", len(listResp.ComplaintList))

		if listResp.Total > 0 {
			fmt.Printf("✅ 找到 %d 条投诉数据！\n", listResp.Total)
			if len(listResp.ComplaintList) > 0 {
				fmt.Printf("\n投诉列表（前3条）:\n")
				for i, item := range listResp.ComplaintList {
					if i >= 3 {
						break
					}
					jsonData, _ := json.MarshalIndent(item, "", "  ")
					fmt.Printf("[%d] %s\n", i+1, string(jsonData))
				}
			}
			// 找到数据后，退出测试
			fmt.Printf("\n✅ 测试成功！找到投诉数据，使用时间范围：%s 到 %s\n", testCase.beginTime, testCase.endTime)
			os.Exit(0)
		} else {
			fmt.Printf("⚠️ 未找到投诉数据\n")
		}
	}

	fmt.Printf("\n❌ 所有测试用例都未找到投诉数据\n")
	fmt.Printf("可能的原因：\n")
	fmt.Printf("1. 该应用确实没有投诉数据\n")
	fmt.Printf("2. API权限问题（应用未开通投诉查询权限）\n")
	fmt.Printf("3. 查询条件仍然不正确\n")
	os.Exit(1)
}
